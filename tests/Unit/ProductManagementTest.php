<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Category;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $category;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->category = Category::create([
            'name' => 'Test Category',
            'description' => 'Test Description'
        ]);
    }

    public function test_product_can_be_created_with_all_required_fields(): void
    {
        $product = Product::create([
            'MaDM' => $this->category->MaDM,
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

        Product::create([
            'MaDM' => $this->category->MaDM,
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

        Product::create([
            'MaDM' => $this->category->MaDM,
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

        Product::create([
            'MaDM' => $this->category->MaDM,
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
        $product = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-003B'
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ProductBatch::create([
            'MaSP' => $product->MaSP,
            'batch_number' => 'BATCH-003C',
            'expiration_date' => now()->addYear(),
            'stock_quantity' => -5,
            'reorder_point' => 10,
        ]);
    }

    public function test_product_expiration_date_must_be_future_date(): void
    {
        $product = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addMonths(3),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-004'
        ]);

        $this->assertTrue($product->expiration_date->isFuture());
    }

    public function test_product_shows_expiration_warning_when_less_than_six_months(): void
    {
        $product = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addMonths(3),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-005'
        ]);

        $this->assertTrue($product->isExpiringSoon(180));
    }

    public function test_batch_number_must_be_unique_per_product(): void
    {
        Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Medicine A',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-UNIQUE'
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Same product name and batch number should fail
        Product::create([
            'MaDM' => $this->category->MaDM,
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
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'status' => 'active'
        ]);

        $this->actingAs($admin);

        $product = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-006'
        ]);

        $product->update(['price' => 60000]);

        $this->assertDatabaseHas('audit_logs', [
            'model_type' => Product::class,
            'MaDoiTuong' => $product->MaSP,
            'action' => 'updated',
            'MaKH' => $admin->MaKH
        ]);
    }

    public function test_product_soft_delete_creates_audit_log(): void
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'status' => 'active'
        ]);

        $this->actingAs($admin);

        $product = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-007'
        ]);

        $product->delete();

        $this->assertSoftDeleted('products', [
            'MaSP' => $product->MaSP
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'model_type' => Product::class,
            'MaDoiTuong' => $product->MaSP,
            'action' => 'deleted',
            'MaKH' => $admin->MaKH
        ]);
    }

    public function test_product_is_expired_method_works_correctly(): void
    {
        $expiredProduct = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Expired Medicine',
            'price' => 50000,
            'expiration_date' => now()->subDay(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-008'
        ]);

        $validProduct = Product::create([
            'MaDM' => $this->category->MaDM,
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
        $lowStockProduct = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Low Stock Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 5,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-010'
        ]);

        $normalStockProduct = Product::create([
            'MaDM' => $this->category->MaDM,
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
