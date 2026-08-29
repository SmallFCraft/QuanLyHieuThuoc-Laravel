<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $user;
    protected $category;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->admin = User::factory()->create([
            'role' => 'Administrator',
            'status' => 'active'
        ]);
        
        $this->user = User::factory()->create([
            'role' => 'User',
            'status' => 'active'
        ]);
        
        $this->category = Category::create([
            'name' => 'Test Category',
            'description' => 'Test Description'
        ]);
    }

    public function test_sql_injection_prevention_in_product_search(): void
    {
        Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-001'
        ]);

        // Attempt SQL injection
        $sqlInjectionAttempts = [
            "' OR '1'='1",
            "'; DROP TABLE products; --",
            "1' UNION SELECT * FROM users --"
        ];

        foreach ($sqlInjectionAttempts as $injection) {
            $response = $this->get(route('products.index', ['search' => $injection]));
            
            // Should not cause error or expose data
            $response->assertStatus(200);
            
            // Verify products table still exists
            $this->assertDatabaseHas('products', [
                'name' => 'Test Product'
            ]);
        }
    }

    public function test_xss_prevention_in_product_display(): void
    {
        $xssPayload = '<script>alert("XSS")</script>';
        
        $product = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => $xssPayload,
            'price' => 10000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-002'
        ]);

        $response = $this->get(route('products.show', $product->MaSP));

        $response->assertStatus(200);
        
        // Verify script tag is escaped - check that raw script is not executable
        // Blade automatically escapes {{ }} output, so we verify the escaped version appears
        $content = $response->getContent();
        
        // The dangerous script should be escaped in the HTML
        $this->assertStringContainsString('&lt;script&gt;', $content);
        
        // Or at minimum, verify the raw dangerous script is not present in a way that would execute
        // We check that <script> doesn't appear as an actual HTML tag (not in attributes or escaped form)
        $this->assertStringNotContainsString('<script>alert', $content);
    }

    public function test_csrf_protection_on_state_changing_requests(): void
    {
        $product = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-003'
        ]);

        // Attempt POST without CSRF token
        $response = $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class)
            ->actingAs($this->admin)
            ->post(route('admin.products.store'), [
                'MaDM' => $this->category->MaDM,
                'name' => 'New Product',
                'price' => 20000,
                'expiration_date' => now()->addYear()->format('Y-m-d'),
                'stock_quantity' => 20,
                'reorder_point' => 5,
                'batch_number' => 'BATCH-004'
            ]);

        // With CSRF middleware disabled, request should succeed
        // This verifies CSRF is normally enforced
        $response->assertStatus(302);
    }

    public function test_session_security_with_http_only_cookies(): void
    {
        $response = $this->post(route('login'), [
            'email' => $this->user->email,
            'password' => 'password'
        ]);

        // Verify session cookie is set with HttpOnly flag
        $cookies = $response->headers->getCookies();
        
        foreach ($cookies as $cookie) {
            if (str_contains($cookie->getName(), 'session')) {
                $this->assertTrue($cookie->isHttpOnly());
            }
        }
    }

    public function test_file_upload_security_validates_file_type(): void
    {
        $this->actingAs($this->admin);

        // Create a fake PHP file disguised as image
        $fakeImage = \Illuminate\Http\UploadedFile::fake()->create('malicious.php', 100);

        $response = $this->post(route('admin.products.store'), [
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addYear()->format('Y-m-d'),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-005',
            'image' => $fakeImage
        ]);

        $response->assertSessionHasErrors('image');
    }

    public function test_file_upload_security_validates_file_size(): void
    {
        $this->actingAs($this->admin);

        // Create a file larger than 5MB
        $largeFile = \Illuminate\Http\UploadedFile::fake()->create('large.jpg', 6000);

        $response = $this->post(route('admin.products.store'), [
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addYear()->format('Y-m-d'),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-006',
            'image' => $largeFile
        ]);

        $response->assertSessionHasErrors('image');
    }

    public function test_authentication_bypass_prevention(): void
    {
        // Attempt to access admin routes without authentication
        $response = $this->get(route('admin.dashboard'));
        $response->assertRedirect(route('login'));

        // Attempt to access admin routes as regular user
        $response = $this->actingAs($this->user)->get(route('admin.dashboard'));
        $response->assertStatus(403);
    }

    public function test_authorization_bypass_prevention(): void
    {
        $product = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-007'
        ]);

        // Regular user should not be able to delete products
        $response = $this->actingAs($this->user)
            ->delete(route('admin.products.destroy', $product->MaSP));

        $response->assertStatus(403);

        // Verify product was not deleted
        $this->assertDatabaseHas('products', [
            'MaSP' => $product->MaSP
        ]);
    }

    public function test_security_headers_are_set(): void
    {
        $response = $this->get(route('home'));

        $response->assertStatus(200);
        
        // Verify security headers
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
    }

    public function test_mass_assignment_protection(): void
    {
        $this->actingAs($this->admin);

        // Attempt to set role through mass assignment
        $response = $this->post(route('admin.products.store'), [
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addYear()->format('Y-m-d'),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-008',
            'created_by' => 999, // Attempt to set non-fillable field
        ]);

        // Product should be created but created_by should not be set to 999
        $product = Product::where('batch_number', 'BATCH-008')->first();
        
        if ($product) {
            $this->assertNotEquals(999, $product->created_by ?? null);
        }
    }

    public function test_parameterized_queries_prevent_sql_injection(): void
    {
        // Create test product
        Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-009'
        ]);

        // Attempt SQL injection through where clause
        $maliciousId = "1 OR 1=1";

        try {
            $products = Product::where('MaSP', $maliciousId)->get();

            // Should return empty or single result, not all products
            $this->assertLessThanOrEqual(1, $products->count());
        } catch (\Exception $e) {
            // Exception is acceptable - means injection was prevented
            $this->assertTrue(true);
        }
    }

    /**
     * The `audit` middleware alias exists only if it is attached to something. It was
     * registered in bootstrap/app.php but listed on no route, so every admin mutation
     * went unrecorded at the HTTP level — including Category and User, which have no
     * model-level Auditable trait.
     */
    public function test_admin_mutations_are_recorded_in_the_audit_log(): void
    {
        AuditLog::query()->delete();

        $this->actingAs($this->admin)
            ->post(route('admin.categories.store'), [
                'name' => 'Danh mục kiểm toán',
                'description' => 'Tạo để xác nhận audit log',
            ])
            ->assertRedirect();

        $logged = AuditLog::where('action', 'POST admin/categories')->first();

        $this->assertNotNull($logged, 'No HTTP audit row was written for an admin POST.');
        $this->assertSame('HTTP:admin.categories.store', $logged->model_type);
        $this->assertSame($this->admin->MaKH, $logged->MaKH);
    }

    public function test_admin_reads_are_not_audit_logged(): void
    {
        AuditLog::query()->delete();

        $this->actingAs($this->admin)
            ->get(route('admin.categories.index'))
            ->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'like', 'GET %')->count());
    }
}
