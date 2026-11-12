<?php

namespace Tests\Unit;

use App\Models\SanPham;
use App\Models\DanhMuc;
use App\Models\NguoiDung;
use App\Models\NhatKyKiemToan;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $danhMuc;
    protected $admin;
    protected $inventoryService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->danhMuc = DanhMuc::create([
            'name' => 'Test Category',
            'description' => 'Test Description'
        ]);

        $this->admin = NguoiDung::factory()->create([
            'role' => 'Administrator',
            'status' => 'active'
        ]);

        $this->inventoryService = app(InventoryService::class);
    }

    public function test_low_stock_alert_when_quantity_below_reorder_point(): void
    {
        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Low Stock Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 5,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-001'
        ]);

        $lowStockProducts = $this->inventoryService->getLowStockProducts();

        $this->assertTrue($lowStockProducts->contains($sanPham));
    }

    public function test_fefo_sorting_by_expiration_date(): void
    {
        $product1 = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Medicine A',
            'price' => 50000,
            'expiration_date' => now()->addMonths(3),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-002'
        ]);

        $product2 = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Medicine B',
            'price' => 50000,
            'expiration_date' => now()->addMonths(1),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-003'
        ]);

        $product3 = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Medicine C',
            'price' => 50000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-004'
        ]);

        $sortedProducts = SanPham::orderBy('expiration_date', 'asc')->get();

        $this->assertEquals($product2->MaSP, $sortedProducts->first()->MaSP);
        $this->assertEquals($product3->MaSP, $sortedProducts->last()->MaSP);
    }

    public function test_inventory_adjustment_with_reason_code(): void
    {
        $this->actingAs($this->admin);

        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-005'
        ]);

        $result = $this->inventoryService->adjustStock(
            $sanPham->MaSP,
            20,
            'Received new shipment',
            $this->admin->MaKH
        );

        $this->assertTrue($result);

        $sanPham->refresh();
        $this->assertEquals(120, $sanPham->stock_quantity);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => SanPham::class,
            'MaDoiTuong' => $sanPham->MaSP,
            'action' => 'inventory_adjustment',
            'MaKH' => $this->admin->MaKH
        ]);
    }

    public function test_prevent_negative_stock_quantities(): void
    {
        $this->actingAs($this->admin);

        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-006'
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Số lượng tồn kho không thể âm');

        $this->inventoryService->adjustStock(
            $sanPham->MaSP,
            -15,
            'Invalid adjustment',
            $this->admin->MaKH
        );
    }

    public function test_concurrent_stock_updates_with_locking(): void
    {
        // Không tạo lô (bỏ qua observer): test thao tác trực tiếp cột products.stock_quantity
        $sanPham = SanPham::withoutEvents(function () {
            return SanPham::create([
                'MaDM' => $this->danhMuc->MaDM,
                'name' => 'Test Medicine',
                'price' => 50000,
                'expiration_date' => now()->addYear(),
                'stock_quantity' => 100,
                'reorder_point' => 10,
                'batch_number' => 'BATCH-007',
            ]);
        });

        // Simulate concurrent updates
        DB::transaction(function () use ($sanPham) {
            $lockedProduct = SanPham::lockForUpdate()->find($sanPham->MaSP);
            $lockedProduct->stock_quantity -= 10;
            $lockedProduct->save();
        });

        DB::transaction(function () use ($sanPham) {
            $lockedProduct = SanPham::lockForUpdate()->find($sanPham->MaSP);
            $lockedProduct->stock_quantity -= 5;
            $lockedProduct->save();
        });

        $sanPham->refresh();
        $this->assertEquals(85, $sanPham->stock_quantity);
    }

    public function test_check_expiration_returns_expiring_products(): void
    {
        $expiringProduct = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Expiring Medicine',
            'price' => 50000,
            'expiration_date' => now()->addDays(15),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-008'
        ]);

        $validProduct = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Valid Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-009'
        ]);

        $expiringProducts = $this->inventoryService->checkExpiration(30);

        $this->assertTrue($expiringProducts->contains($expiringProduct));
        $this->assertFalse($expiringProducts->contains($validProduct));
    }

    public function test_inventory_adjustment_creates_audit_log_with_details(): void
    {
        $this->actingAs($this->admin);

        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-010'
        ]);

        $this->inventoryService->adjustStock(
            $sanPham->MaSP,
            150,
            'Stock replenishment',
            $this->admin->MaKH
        );

        $nhatKy = NhatKyKiemToan::where('MaDoiTuong', $sanPham->MaSP)
            ->where('action', 'inventory_adjustment')
            ->first();

        $this->assertNotNull($nhatKy);
        $this->assertEquals($this->admin->MaKH, $nhatKy->MaKH);
        $this->assertNotNull($nhatKy->old_values);
        $this->assertNotNull($nhatKy->new_values);
    }
}
