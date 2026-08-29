<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\UserHealthProfile;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartDrugSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // MedicalKnowledgeSeeder resolves categories by name, so it must exist first.
        $this->category = Category::create([
            'name' => 'Thuốc giảm đau - Hạ sốt',
            'description' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(MedicalKnowledgeSeeder::class);

        $this->user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
            'birth_date' => now()->subYears(34)->toDateString(),
        ]);
    }

    public function test_major_interaction_blocks_the_second_item(): void
    {
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $this->actingAs($this->user)->post(route('cart.add'), [
            'MaSP' => $aspirin->MaSP,
            'quantity' => 1,
        ])->assertSessionHas('success');

        $response = $this->actingAs($this->user)->post(route('cart.add'), [
            'MaSP' => $ibuprofen->MaSP,
            'quantity' => 1,
        ]);

        $response->assertRedirect(route('cart.index'));
        $response->assertSessionHas('drug_warnings');

        $cart = session('cart');
        $this->assertCount(1, $cart);
        $this->assertArrayNotHasKey($ibuprofen->MaSP, $cart);

        // The cart page must render the acknowledgement form that replays the request.
        $this->actingAs($this->user)->get(route('cart.index'))
            ->assertOk()
            ->assertSee('name="confirm_warning"', false)
            ->assertSee('bi-exclamation-octagon-fill', false);
    }

    public function test_confirmation_adds_the_item_and_records_the_override(): void
    {
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        session(['cart' => [$aspirin->MaSP => 1]]);

        $response = $this->actingAs($this->user)->post(route('cart.add'), [
            'MaSP' => $ibuprofen->MaSP,
            'quantity' => 1,
            'confirm_warning' => 1,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $cart = session('cart');
        $this->assertCount(2, $cart);
        $this->assertArrayHasKey($ibuprofen->MaSP, $cart);

        $log = AuditLog::where('action', 'drug_warning_overridden')->first();
        $this->assertNotNull($log);
        $this->assertSame($ibuprofen->MaSP, $log->MaDoiTuong);
    }

    public function test_declared_allergy_blocks_the_matching_product(): void
    {
        $this->healthProfile(['allergies' => ['Paracetamol'], 'consented_at' => now()]);
        $panadol = $this->product('Panadol', 'Paracetamol 500mg');

        $response = $this->actingAs($this->user)->post(route('cart.add'), [
            'MaSP' => $panadol->MaSP,
            'quantity' => 1,
        ]);

        $response->assertSessionHas('drug_warnings');
        $this->assertEmpty(session('cart', []));
    }

    public function test_profile_without_consent_is_not_read(): void
    {
        $this->healthProfile(['allergies' => ['Paracetamol'], 'consented_at' => null]);
        $panadol = $this->product('Panadol', 'Paracetamol 500mg');

        $response = $this->actingAs($this->user)->post(route('cart.add'), [
            'MaSP' => $panadol->MaSP,
            'quantity' => 1,
        ]);

        $response->assertSessionHas('success');
        $response->assertSessionMissing('drug_warnings');
        $this->assertArrayHasKey($panadol->MaSP, session('cart'));
    }

    public function test_unrelated_product_is_added_without_warnings(): void
    {
        $vitamin = $this->product('Vitamin E', 'Vitamin E 400IU');

        $response = $this->actingAs($this->user)->post(route('cart.add'), [
            'MaSP' => $vitamin->MaSP,
            'quantity' => 2,
        ]);

        $response->assertSessionHas('success');
        $response->assertSessionMissing('drug_warnings');
        $this->assertEquals(2, session('cart')[$vitamin->MaSP]);
    }

    public function test_json_branch_reports_that_confirmation_is_required(): void
    {
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        session(['cart' => [$aspirin->MaSP => 1]]);

        $response = $this->actingAs($this->user)->postJson(route('cart.add'), [
            'MaSP' => $ibuprofen->MaSP,
            'quantity' => 1,
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'success' => false,
            'requires_confirmation' => true,
        ]);
        $this->assertNotEmpty($response->json('drug_warnings'));
        $this->assertArrayNotHasKey($ibuprofen->MaSP, session('cart'));
    }

    public function test_json_branch_confirmation_adds_the_item(): void
    {
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        session(['cart' => [$aspirin->MaSP => 1]]);

        $response = $this->actingAs($this->user)->postJson(route('cart.add'), [
            'MaSP' => $ibuprofen->MaSP,
            'quantity' => 1,
            'confirm_warning' => 1,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'requires_confirmation' => false,
            'cart_count' => 2,
        ]);
    }

    public function test_soft_warning_is_returned_without_blocking(): void
    {
        $panadol = $this->product('Panadol', 'Paracetamol 500mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        session(['cart' => [$panadol->MaSP => 1]]);

        $response = $this->actingAs($this->user)->postJson(route('cart.add'), [
            'MaSP' => $ibuprofen->MaSP,
            'quantity' => 1,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'requires_confirmation' => false]);
        $this->assertNotEmpty($response->json('drug_warnings'));
        $this->assertArrayHasKey($ibuprofen->MaSP, session('cart'));
    }

    public function test_checkout_shows_the_warnings_but_still_allows_ordering(): void
    {
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        session(['cart' => [$aspirin->MaSP => 1, $ibuprofen->MaSP => 1]]);

        $response = $this->actingAs($this->user)->get(route('checkout.index'));

        $response->assertOk();
        $response->assertViewIs('checkout.index');
        // Structural marker, not the label: the lang key is wired into messages.php separately.
        $response->assertSee('bi-exclamation-octagon-fill', false);
        $response->assertSee('role="alert"', false);
        $this->assertNotEmpty($response->viewData('drugWarnings'));
    }

    public function test_empty_cart_checkout_does_not_error(): void
    {
        $response = $this->actingAs($this->user)->get(route('checkout.index'));

        $response->assertRedirect(route('cart.index'));
        $response->assertSessionHas('error');
    }

    protected function healthProfile(array $attributes = []): UserHealthProfile
    {
        return UserHealthProfile::updateOrCreate(
            ['MaKH' => $this->user->MaKH],
            array_merge(['consented_at' => now()], $attributes)
        );
    }

    protected function product(string $name, string $ingredients): Product
    {
        return Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => $name,
            'ingredients' => $ingredients,
            'usage' => 'Theo hướng dẫn',
            'price' => 5000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 50,
            'reorder_point' => 5,
            'batch_number' => 'B-' . uniqid(),
        ]);
    }
}
