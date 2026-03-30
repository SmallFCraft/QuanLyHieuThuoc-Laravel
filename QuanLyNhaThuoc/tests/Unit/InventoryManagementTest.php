<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Category;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $category;
    protected $admin;
    protected $inventoryService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->category = Category::create([
            'name' => 'Test Category',
            'description' => 'Test Description'
        ]);

        $this->admin = User::factory()->create([
            'role' => 'Administrator',
            'status' => 'active'
        ]);

        $this->inventoryService = app(InventoryService::class);
    }

    public function test_low_stock_alert_when_quantity_below_reorder_point(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Low Stock Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 5,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-001'
        ]);

        $lowStockProducts = $this->inventoryService->getLowStockProducts();

        $this->assertTrue($lowStockProducts->contains($product));
    }

    public function test_fefo_sorting_by_expiration_date(): void
    {
        $product1 = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Medicine A',
            'price' => 50000,
            'expiration_date' => now()->addMonths(3),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-002'
        ]);

        $product2 = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Medicine B',
            'price' => 50000,
            'expiration_date' => now()->addMonths(1),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-003'
        ]);

        $product3 = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Medicine C',
            'price' => 50000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-004'
        ]);

        $sortedProducts = Product::orderBy('expiration_date', 'asc')->get();

        $this->assertEquals($product2->id, $sortedProducts->first()->id);
        $this->assertEquals($product3->id, $sortedProducts->last()->id);
    }

    public function test_inventory_adjustment_with_reason_code(): void
    {
        $this->actingAs($this->admin);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-005'
        ]);

        $result = $this->inventoryService->adjustStock(
            $product->id,
            20,
            'Received new shipment',
            $this->admin->id
        );

        $this->assertTrue($result);

        $product->refresh();
        $this->assertEquals(120, $product->stock_quantity);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => Product::class,
            'model_id' => $product->id,
            'action' => 'inventory_adjustment',
            'user_id' => $this->admin->id
        ]);
    }

    public function test_prevent_negative_stock_quantities(): void
    {
        $this->actingAs($this->admin);

        $product = Product::create([
            'category_id' => $this->category->id,
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
            $product->id,
            -15,
            'Invalid adjustment',
            $this->admin->id
        );
    }

    public function test_concurrent_stock_updates_with_locking(): void
    {
        // Không tạo lô (bỏ qua observer): test thao tác trực tiếp cột products.stock_quantity
        $product = Product::withoutEvents(function () {
            return Product::create([
                'category_id' => $this->category->id,
                'name' => 'Test Medicine',
                'price' => 50000,
                'expiration_date' => now()->addYear(),
                'stock_quantity' => 100,
                'reorder_point' => 10,
                'batch_number' => 'BATCH-007',
            ]);
        });

        // Simulate concurrent updates
        DB::transaction(function () use ($product) {
            $lockedProduct = Product::lockForUpdate()->find($product->id);
            $lockedProduct->stock_quantity -= 10;
            $lockedProduct->save();
        });

        DB::transaction(function () use ($product) {
            $lockedProduct = Product::lockForUpdate()->find($product->id);
            $lockedProduct->stock_quantity -= 5;
            $lockedProduct->save();
        });

        $product->refresh();
        $this->assertEquals(85, $product->stock_quantity);
    }

    public function test_check_expiration_returns_expiring_products(): void
    {
        $expiringProduct = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Expiring Medicine',
            'price' => 50000,
            'expiration_date' => now()->addDays(15),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-008'
        ]);

        $validProduct = Product::create([
            'category_id' => $this->category->id,
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

        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-010'
        ]);

        $this->inventoryService->adjustStock(
            $product->id,
            150,
            'Stock replenishment',
            $this->admin->id
        );

        $auditLog = AuditLog::where('model_id', $product->id)
            ->where('action', 'inventory_adjustment')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals($this->admin->id, $auditLog->user_id);
        $this->assertNotNull($auditLog->old_values);
        $this->assertNotNull($auditLog->new_values);
    }
}
