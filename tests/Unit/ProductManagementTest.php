<?php

namespace Tests\Unit;

use App\Models\SanPham;
use App\Models\LoSanPham;
use App\Models\DanhMuc;
use App\Models\NguoiDung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $danhMuc;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->danhMuc = DanhMuc::create([
            'name' => 'Test Category',
            'description' => 'Test Description'
        ]);
    }

    public function test_product_can_be_created_with_all_required_fields(): void
    {
        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Medicine',
            'ingredients' => 'Active ingredient',
            'usage' => 'Take twice daily',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-001'
        ]);

        $this->assertDatabaseHas('products', [
            'name' => 'Test Medicine',
            'batch_number' => 'BATCH-001'
        ]);
    }

    public function test_product_rejects_negative_price(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Medicine',
            'price' => -1000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-002'
        ]);
    }

    public function test_product_rejects_negative_stock_quantity(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => -10,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-003'
        ]);
    }

    public function test_product_rejects_negative_reorder_point(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 10,
            'reorder_point' => -1,
            'batch_number' => 'BATCH-003A'
        ]);
    }

    public function test_product_batch_rejects_negative_stock_quantity(): void
    {
        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-003B'
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        LoSanPham::create([
            'MaSP' => $sanPham->MaSP,
            'batch_number' => 'BATCH-003C',
            'expiration_date' => now()->addYear(),
            'stock_quantity' => -5,
            'reorder_point' => 10,
        ]);
    }

    public function test_product_expiration_date_must_be_future_date(): void
    {
        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addMonths(3),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-004'
        ]);

        $this->assertTrue($sanPham->expiration_date->isFuture());
    }

    public function test_product_shows_expiration_warning_when_less_than_six_months(): void
    {
        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addMonths(3),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-005'
        ]);

        $this->assertTrue($sanPham->isExpiringSoon(180));
    }

    public function test_a_distant_expiry_is_not_flagged_as_expiring_soon(): void
    {
        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Medicine Far',
            'price' => 50000,
            'expiration_date' => now()->addMonths(36),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-005-FAR',
        ]);

        // The signed Carbon 3 difference made this return true for every future
        // date, so the inventory screen labelled the whole catalog "Sắp hết hạn".
        $this->assertFalse($sanPham->isExpiringSoon(30));
        $this->assertFalse($sanPham->isExpiringSoon(180));

        $lo = LoSanPham::where('MaSP', $sanPham->MaSP)->sole();
        $this->assertFalse($lo->isExpiringSoon(30));
    }

    public function test_the_expiring_soon_boundary_matches_the_query_scope(): void
    {
        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Medicine Boundary',
            'price' => 50000,
            'expiration_date' => now()->addDays(20),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-005-EDGE',
        ]);

        // The summary cards count with scopeExpiringSoon() while the table labels
        // rows with isExpiringSoon(); disagreement shows a wrong count next to the
        // rows it claims to describe.
        $this->assertTrue($sanPham->isExpiringSoon(30));
        $this->assertSame(1, SanPham::expiringSoon(30)->count());
    }

    public function test_batch_number_must_be_unique_per_product(): void
    {
        SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Medicine A',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-UNIQUE'
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Same product name and batch number should fail
        SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Medicine A',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-UNIQUE'
        ]);
    }

    public function test_product_update_creates_audit_log(): void
    {
        $admin = NguoiDung::factory()->create([
            'role' => 'Administrator',
            'status' => 'active'
        ]);

        $this->actingAs($admin);

        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-006'
        ]);

        $sanPham->update(['price' => 60000]);

        $this->assertDatabaseHas('audit_logs', [
            'model_type' => SanPham::class,
            'MaDoiTuong' => $sanPham->MaSP,
            'action' => 'updated',
            'MaKH' => $admin->MaKH
        ]);
    }

    public function test_product_soft_delete_creates_audit_log(): void
    {
        $admin = NguoiDung::factory()->create([
            'role' => 'Administrator',
            'status' => 'active'
        ]);

        $this->actingAs($admin);

        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-007'
        ]);

        $sanPham->delete();

        $this->assertSoftDeleted('products', [
            'MaSP' => $sanPham->MaSP
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'model_type' => SanPham::class,
            'MaDoiTuong' => $sanPham->MaSP,
            'action' => 'deleted',
            'MaKH' => $admin->MaKH
        ]);
    }

    public function test_product_is_expired_method_works_correctly(): void
    {
        $expiredProduct = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Expired Medicine',
            'price' => 50000,
            'expiration_date' => now()->subDay(),
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

        $this->assertTrue($expiredProduct->isExpired());
        $this->assertFalse($validProduct->isExpired());
    }

    public function test_product_is_low_stock_method_works_correctly(): void
    {
        $lowStockProduct = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Low Stock Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 5,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-010'
        ]);

        $normalStockProduct = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Normal Stock Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-011'
        ]);

        $this->assertTrue($lowStockProduct->isLowStock());
        $this->assertFalse($normalStockProduct->isLowStock());
    }
}
