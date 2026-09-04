<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ChatMessage;
use App\Models\DrugInteraction;
use App\Models\MedicalRedFlag;
use App\Models\MedicalSymptom;
use App\Models\MedicalTriageRule;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\SearchQuery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WipeCatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The command deletes files under the public disk, so every test in this class
     * runs against a faked one — without this, a suite run wipes the developer's
     * real storage/app/public/products.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * A catalog with an order against it — the case where a plain delete on
     * products fails, because order_items.MaSP restricts.
     */
    private function seedCatalogWithAnOrder(): Product
    {
        $product = Product::factory()->create(['MaDM' => Category::factory()]);
        ProductBatch::create([
            'MaSP' => $product->MaSP,
            'batch_number' => 'B-1',
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 10,
            'reorder_point' => 5,
        ]);

        $order = Order::create([
            'MaKH' => User::factory()->create()->MaKH,
            'total_amount' => 1000,
            'status' => 'Pending',
            'shipping_address' => '1 Test',
            'shipping_phone' => '0901234567',
        ]);

        OrderItem::create([
            'MaDH' => $order->MaDH,
            'MaSP' => $product->MaSP,
            'quantity' => 1,
            'unit_price' => 1000,
            'batch_number' => 'B-1',
        ]);

        return $product;
    }

    public function test_it_clears_the_catalog_and_the_orders_that_reference_it(): void
    {
        $this->seedCatalogWithAnOrder();

        $this->artisan('db:wipe-catalog --force')->assertSuccessful();

        $this->assertSame(0, Product::withTrashed()->count());
        $this->assertSame(0, ProductBatch::count());
        $this->assertSame(0, Category::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
    }

    public function test_it_keeps_users_and_medical_knowledge(): void
    {
        $this->seedCatalogWithAnOrder();
        $userCount = User::count();

        // Nội dung y khoa do người soạn tay, không crawl lại được. Nó không nằm trong
        // TABLES, nhưng chỉ assert User::count() thì thêm một bảng y khoa vào danh sách
        // đó — điều rất dễ xảy ra khi một bảng có thêm khoá ngoại tới products — vẫn
        // xanh trong khi dữ liệu mất vĩnh viễn.
        $symptom = MedicalSymptom::create([
            'code' => 'sot',
            'name_vi' => 'Sốt',
            'aliases' => ['sot cao'],
            'guard_words' => [],
            'body_system' => 'general',
        ]);

        MedicalRedFlag::create([
            'code' => 'cung-co',
            'name_vi' => 'Cứng cổ',
            'keywords' => ['cung co'],
            'severity' => 'emergency',
            'guidance_vi' => 'Gọi 115.',
        ]);

        MedicalTriageRule::create([
            'MaTC' => $symptom->MaTC,
            'conditions' => [],
            'outcome' => 'self_care',
            'advice_vi' => 'Nghỉ ngơi, uống nhiều nước.',
        ]);

        DrugInteraction::create([
            'ingredient_a' => 'paracetamol',
            'ingredient_b' => 'warfarin',
            'severity' => 'moderate',
            'description_vi' => 'Tăng nguy cơ chảy máu.',
            'recommendation_vi' => 'Hỏi dược sĩ.',
        ]);

        $this->artisan('db:wipe-catalog --force')->assertSuccessful();

        $this->assertSame($userCount, User::count());
        $this->assertSame(1, MedicalSymptom::count());
        $this->assertSame(1, MedicalRedFlag::count());
        $this->assertSame(1, MedicalTriageRule::count());
        $this->assertSame(1, DrugInteraction::count());
    }

    public function test_it_clears_the_search_log_so_trending_stops_naming_dead_products(): void
    {
        SearchQuery::create([
            'term_folded' => 'paracetamol',
            'term' => 'paracetamol',
            'hits' => 9,
            'last_searched_at' => now(),
        ]);
        SearchQuery::trending();

        $this->artisan('db:wipe-catalog --force')->assertSuccessful();

        $this->assertSame(0, SearchQuery::count());
        // Cache cũng phải rơi: pill vẫn hiện 10 phút nữa dù bảng đã trống.
        $this->assertFalse(Cache::has(SearchQuery::CACHE_KEY));
    }

    public function test_declining_the_prompt_changes_nothing(): void
    {
        $this->seedCatalogWithAnOrder();

        $this->artisan('db:wipe-catalog')
            ->expectsConfirmation('Tiếp tục?', 'no')
            ->assertSuccessful();

        $this->assertSame(1, Product::count());
        $this->assertSame(1, Order::count());
    }

    public function test_it_deletes_uploaded_product_images(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/paracetamol.jpg', 'image');

        $this->artisan('db:wipe-catalog --force')->assertSuccessful();

        Storage::disk('public')->assertMissing('products/paracetamol.jpg');
    }

    public function test_keep_images_leaves_the_files_alone(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/paracetamol.jpg', 'image');

        $this->artisan('db:wipe-catalog --force --keep-images')->assertSuccessful();

        Storage::disk('public')->assertExists('products/paracetamol.jpg');
    }

    public function test_product_snapshots_on_surviving_records_are_emptied(): void
    {
        $product = $this->seedCatalogWithAnOrder();

        $message = ChatMessage::create([
            'MaKH' => User::factory()->create()->MaKH,
            'session_id' => (string) \Illuminate\Support\Str::uuid(),
            'role' => 'assistant',
            'content' => 'Sản phẩm gợi ý',
            'products' => [['id' => $product->MaSP, 'name' => $product->name]],
        ]);

        $this->artisan('db:wipe-catalog --force')->assertSuccessful();

        // The message outlives the catalog, so its stored product block has to stop
        // linking to ids that no longer exist.
        $this->assertSame([], $message->fresh()->products);
    }

    public function test_ids_restart_so_the_new_catalog_is_numbered_from_one(): void
    {
        $this->seedCatalogWithAnOrder();

        $this->artisan('db:wipe-catalog --force')->assertSuccessful();

        $fresh = Product::factory()->create(['MaDM' => Category::factory()]);

        $this->assertSame(1, $fresh->MaSP);
    }

    public function test_it_runs_on_an_already_empty_database(): void
    {
        $this->artisan('db:wipe-catalog --force')->assertSuccessful();

        $this->assertSame(0, Product::count());
    }
}
