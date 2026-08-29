<?php

namespace Tests\Unit\Services;

use App\Models\Category;
use App\Models\DrugContraindication;
use App\Models\Product;
use App\Models\User;
use App\Models\UserHealthProfile;
use App\Services\DrugSafetyService;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DrugSafetyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DrugSafetyService $service;
    protected Category $category;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Thuốc giảm đau - Hạ sốt',
            'description' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(MedicalKnowledgeSeeder::class);

        $this->service = app(DrugSafetyService::class);

        $this->user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
            'birth_date' => now()->subYears(34)->toDateString(),
        ]);
    }

    // --- Ingredient extraction ---

    public function test_dosage_is_stripped_from_the_ingredient_name(): void
    {
        $this->assertSame(['paracetamol'], $this->service->extractIngredients('Paracetamol 500mg'));
    }

    public function test_combination_products_yield_every_ingredient(): void
    {
        $found = $this->service->extractIngredients('Amoxicillin 500mg + Acid clavulanic 125mg');

        $this->assertContains('amoxicillin', $found);
        $this->assertContains('acid clavulanic', $found);
    }

    public function test_compound_names_survive_tokenisation(): void
    {
        $this->assertSame(['acid acetylsalicylic'], $this->service->extractIngredients('Acid acetylsalicylic 100mg'));
    }

    public function test_salt_and_unit_words_are_not_treated_as_ingredients(): void
    {
        $found = $this->service->extractIngredients('Amlodipine besylate 5mg');

        $this->assertContains('amlodipine', $found);
        $this->assertNotContains('besylate', $found);
        $this->assertNotContains('mg', $found);
    }

    public function test_empty_ingredient_string_yields_nothing(): void
    {
        $this->assertSame([], $this->service->extractIngredients(''));
    }

    // --- Pair interactions ---

    public function test_two_nsaids_are_a_major_interaction(): void
    {
        $found = $this->service->checkPair('Acid acetylsalicylic 100mg', 'Ibuprofen 400mg');

        $this->assertCount(1, $found);
        $this->assertSame('major', $found->first()->severity);
    }

    public function test_interaction_lookup_is_order_independent(): void
    {
        $forward = $this->service->checkPair('Ibuprofen 400mg', 'Diclofenac sodium 50mg');
        $reverse = $this->service->checkPair('Diclofenac sodium 50mg', 'Ibuprofen 400mg');

        $this->assertCount(1, $forward);
        $this->assertSame($forward->first()->MaTT, $reverse->first()->MaTT);
    }

    public function test_unrelated_products_report_no_interaction(): void
    {
        $this->assertCount(0, $this->service->checkPair('Paracetamol 500mg', 'Vitamin E 400IU'));
    }

    public function test_same_ingredient_twice_is_not_reported_as_an_interaction(): void
    {
        $this->assertCount(0, $this->service->checkPair('Paracetamol 500mg', 'Paracetamol 650mg'));
    }

    // --- Contraindications driven by the health profile ---

    public function test_declared_allergy_blocks_the_matching_product(): void
    {
        $this->profile(['allergies' => ['Paracetamol']]);
        $product = $this->product('Panadol', 'Paracetamol 500mg');

        $result = $this->service->checkProduct($product, $this->consentedProfile(), $this->user);

        $this->assertTrue($result['blocking']);
        $this->assertSame('allergy', $result['warnings'][0]['type']);
    }

    public function test_pregnancy_blocks_nsaids(): void
    {
        $this->profile(['is_pregnant' => true]);
        $product = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $result = $this->service->checkProduct($product, $this->consentedProfile(), $this->user);

        $this->assertTrue($result['blocking']);
        $this->assertTrue(
            collect($result['warnings'])->contains(fn ($w) => str_contains($w['title'], 'mang thai'))
        );
    }

    public function test_chronic_condition_blocks_the_contraindicated_drug(): void
    {
        $this->profile(['chronic_conditions' => ['loét dạ dày']]);
        $product = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $result = $this->service->checkProduct($product, $this->consentedProfile(), $this->user);

        $this->assertTrue(
            collect($result['warnings'])->contains(fn ($w) => str_contains($w['title'], 'loét dạ dày'))
        );
    }

    public function test_current_medication_interaction_is_reported(): void
    {
        $this->profile(['current_medications' => ['Clopidogrel 75mg']]);
        $product = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $result = $this->service->checkProduct($product, $this->consentedProfile(), $this->user);

        $this->assertTrue(
            collect($result['warnings'])->contains(fn ($w) => $w['type'] === 'current_medication')
        );
    }

    public function test_profile_without_consent_produces_no_personalised_warning(): void
    {
        $this->profile(['allergies' => ['Paracetamol'], 'consented_at' => null]);
        $product = $this->product('Panadol', 'Paracetamol 500mg');

        // consentedProfile() returns null, mirroring what the controllers pass in.
        $result = $this->service->checkProduct($product, $this->consentedProfile(), $this->user);

        $this->assertFalse($result['blocking']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_age_guard_fires_for_a_child(): void
    {
        $child = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
            'birth_date' => now()->subYears(10)->toDateString(),
        ]);
        $product = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');

        $result = $this->service->checkProduct($product, null, $child);

        $this->assertTrue($result['blocking']);
        $this->assertTrue(
            collect($result['warnings'])->contains(fn ($w) => str_contains($w['title'], 'dưới 16 tuổi'))
        );
    }

    public function test_age_guard_does_not_fire_for_an_adult(): void
    {
        $product = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');

        $result = $this->service->checkProduct($product, null, $this->user);

        $this->assertFalse(
            collect($result['warnings'])->contains(fn ($w) => str_contains($w['title'], 'dưới 16 tuổi'))
        );
    }

    public function test_unknown_age_does_not_silently_pass_an_age_guard(): void
    {
        $noBirthDate = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
            'birth_date' => null,
        ]);
        $product = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');

        $result = $this->service->checkProduct($product, null, $noBirthDate);

        // No age known means the age rule cannot be evaluated, so it must not claim a match.
        $this->assertFalse(
            collect($result['warnings'])->contains(fn ($w) => str_contains($w['title'], 'dưới 16 tuổi'))
        );
    }

    public function test_inactive_contraindications_are_ignored(): void
    {
        DrugContraindication::query()->update(['is_active' => false]);
        $this->profile(['is_pregnant' => true]);
        $product = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $result = $this->service->checkProduct($product, $this->consentedProfile(), $this->user);

        $this->assertFalse(
            collect($result['warnings'])->contains(fn ($w) => $w['type'] === 'contraindication')
        );
    }

    // --- Cart-level scanning ---

    public function test_cart_scan_finds_the_interaction_between_two_items(): void
    {
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $result = $this->service->checkCart([
            ['MaSP' => $aspirin->MaSP, 'quantity' => 1],
            ['MaSP' => $ibuprofen->MaSP, 'quantity' => 1],
        ], $this->user->MaKH);

        $this->assertTrue($result['blocking']);
        $this->assertTrue(collect($result['warnings'])->contains(fn ($w) => $w['type'] === 'interaction'));
    }

    public function test_cart_scan_covers_every_pair_not_just_neighbours(): void
    {
        $vitamin = $this->product('Vitamin E', 'Vitamin E 400IU');
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $result = $this->service->checkCart([
            ['MaSP' => $aspirin->MaSP, 'quantity' => 1],
            ['MaSP' => $vitamin->MaSP, 'quantity' => 1],
            ['MaSP' => $ibuprofen->MaSP, 'quantity' => 1],
        ], $this->user->MaKH);

        $this->assertTrue(collect($result['warnings'])->contains(fn ($w) => $w['type'] === 'interaction'));
    }

    public function test_empty_cart_produces_no_warnings(): void
    {
        $result = $this->service->checkCart([], $this->user->MaKH);

        $this->assertFalse($result['blocking']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_single_safe_item_produces_no_warnings(): void
    {
        $vitamin = $this->product('Vitamin E', 'Vitamin E 400IU');

        $result = $this->service->checkCart(
            [['MaSP' => $vitamin->MaSP, 'quantity' => 1]],
            $this->user->MaKH
        );

        $this->assertFalse($result['blocking']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_addition_check_compares_against_what_is_already_in_the_cart(): void
    {
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $result = $this->service->checkAddition(
            $ibuprofen,
            [['MaSP' => $aspirin->MaSP, 'quantity' => 1]],
            $this->user->MaKH
        );

        $this->assertTrue($result['blocking']);
    }

    public function test_adding_the_same_product_again_is_not_an_interaction_with_itself(): void
    {
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');

        $result = $this->service->checkAddition(
            $aspirin,
            [['MaSP' => $aspirin->MaSP, 'quantity' => 1]],
            $this->user->MaKH
        );

        $this->assertFalse($result['blocking']);
    }

    public function test_warnings_are_sorted_most_severe_first(): void
    {
        $this->profile([
            'allergies' => ['Paracetamol'],
            'chronic_conditions' => ['hen suyễn'],
        ]);
        $paracetamol = $this->product('Panadol', 'Paracetamol 500mg');

        $result = $this->service->checkCart(
            [['MaSP' => $paracetamol->MaSP, 'quantity' => 1]],
            $this->user->MaKH
        );

        $this->assertSame('major', $result['warnings'][0]['severity']);
    }

    public function test_override_is_written_to_the_audit_trail(): void
    {
        $product = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $this->service->logOverride($this->user->MaKH, $product, [
            ['type' => 'interaction', 'severity' => 'major'],
        ]);

        $log = \App\Models\AuditLog::where('action', 'drug_warning_overridden')->latest('MaNK')->first();

        $this->assertNotNull($log);
        $this->assertSame($product->MaSP, $log->MaDoiTuong);
    }

    // --- Helpers ---

    protected function profile(array $attributes = []): UserHealthProfile
    {
        return UserHealthProfile::updateOrCreate(
            ['MaKH' => $this->user->MaKH],
            array_merge(['consented_at' => now()], $attributes)
        );
    }

    protected function consentedProfile(): ?UserHealthProfile
    {
        return UserHealthProfile::where('MaKH', $this->user->MaKH)->consented()->first();
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
