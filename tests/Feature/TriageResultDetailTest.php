<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MedicalSymptom;
use App\Models\MedicalTriageRule;
use App\Models\Product;
use App\Models\TriageSession;
use App\Models\User;
use App\Models\UserHealthProfile;
use App\Services\Triage\MedicalTriageService;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the result page actually tells the customer.
 *
 * The invariants under test: a suggested medicine has to match the symptom (the old
 * phrase-LIKE matched nothing and silently fell back to the highest-stock items in
 * the category, which is how a headache was offered Aspirin 100mg "phòng ngừa đột
 * quỵ" and an itch was offered aspirin at all); the drug-safety check that guards the
 * cart also guards the suggestion; and the page states the facts the rules used.
 */
class TriageResultDetailTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MedicalTriageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Category::create(['name' => 'Thuốc giảm đau - Hạ sốt', 'description' => 'Giảm đau, hạ sốt']);
        $this->seed(MedicalKnowledgeSeeder::class);

        $this->service = app(MedicalTriageService::class);
        $this->user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
            'birth_date' => now()->subYears(34)->toDateString(),
        ]);
    }

    protected function stock(string $name, string $ingredients, string $usage, int $quantity = 50): Product
    {
        return Product::create([
            'MaDM' => Category::first()->MaDM,
            'name' => $name,
            'ingredients' => $ingredients,
            'usage' => $usage,
            'price' => 5000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => $quantity,
            'reorder_point' => 5,
            'batch_number' => 'B-' . uniqid(),
        ]);
    }

    /**
     * @param array<int, string> $answers
     */
    protected function runWizard(array $answers): TriageSession
    {
        $this->actingAs($this->user)->get(route('health.check'));
        $session = TriageSession::where('MaKH', $this->user->MaKH)->inProgress()->latest('MaSL')->firstOrFail();

        foreach ($answers as $step => $answer) {
            $current = TriageSession::find($session->MaSL);

            if ($current === null || $current->status !== 'in_progress') {
                break;
            }

            $this->actingAs($this->user)->post(route('health.answer'), [
                'triage_session_id' => $session->MaSL,
                'step' => $step,
                'answer' => $answer,
            ]);
        }

        return $session->fresh();
    }

    protected function headacheAnswers(): array
    {
        return [
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ];
    }

    public function test_an_unrelated_high_stock_product_is_not_offered_for_a_headache(): void
    {
        $painkiller = $this->stock('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau, hạ sốt. Uống 1-2 viên mỗi 4-6 giờ', 100);
        // Same category, far more stock, but its stated purpose is cardiovascular
        // prevention. The old top-stock fallback put this first for every symptom.
        $unrelated = $this->stock('Aspirin 100mg', 'Acid acetylsalicylic 100mg', 'Phòng ngừa đột quỵ, nhồi máu cơ tim. Uống 1 viên/ngày', 500);

        $session = $this->runWizard($this->headacheAnswers());
        $ids = collect($session->suggested_products)->pluck('id');

        $this->assertTrue($ids->contains($painkiller->MaSP));
        $this->assertFalse($ids->contains($unrelated->MaSP));
    }

    public function test_no_matching_medicine_means_no_suggestion_at_all(): void
    {
        // Only a cardiovascular product in stock: nothing treats an itch, so the page
        // must say so instead of offering aspirin, which makes hives worse.
        $this->stock('Aspirin 100mg', 'Acid acetylsalicylic 100mg', 'Phòng ngừa đột quỵ, nhồi máu cơ tim', 500);

        $session = $this->runWizard([
            1 => 'tôi bị ngứa',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $this->assertSame([], $session->suggested_products);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.products_empty'));
    }

    public function test_relevance_outranks_stock(): void
    {
        $this->stock('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau, hạ sốt', 12);
        $this->stock('Hapacol 650mg', 'Paracetamol 650mg', 'Giảm đau, hạ sốt', 400);
        // Two of the query's words, but the lowest stock of the three.
        $best = $this->stock('Panadol Extra', 'Paracetamol 500mg, Caffeine', 'Giảm đau đầu, hạ sốt nhanh', 8);

        $session = $this->runWizard($this->headacheAnswers());

        $this->assertSame($best->MaSP, $session->suggested_products[0]['id']);
    }

    public function test_a_diacritic_only_overlap_does_not_count_as_a_match(): void
    {
        // "mất ngủ" folds to "mat ngu"; "mất nước" folds to "mat nuoc". Matching on the
        // folded form alone would let an oral rehydration salt answer insomnia.
        MedicalSymptom::where('code', 'insomnia')->update(['catalog_query' => 'mất ngủ']);
        $this->stock('Oresol', 'Glucose, Natri clorid', 'Bù nước và điện giải khi mất nước', 300);

        $session = $this->runWizard([
            1 => 'tôi bị mất ngủ',
            2 => 'hơn 1 tuần',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $this->assertSame([], $session->suggested_products);
    }

    public function test_a_contraindicated_suggestion_is_flagged_on_the_result_page(): void
    {
        $this->stock('Ibuprofen 400mg', 'Ibuprofen 400mg', 'Giảm đau, hạ sốt, chống viêm', 200);

        // An allergy rather than pregnancy: pregnancy fires its own red flag, which
        // routes to refer_doctor and suggests nothing, so it could never exercise this.
        UserHealthProfile::create([
            'MaKH' => $this->user->MaKH,
            'allergies' => ['ibuprofen'],
            'consented_at' => now(),
        ]);

        $session = $this->runWizard($this->headacheAnswers());

        $this->assertSame('self_care', $session->outcome);
        $this->assertNotEmpty($session->suggested_products);

        // The cart blocks this exact product one click later; the warning has to appear
        // where the suggestion is made.
        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.safety_check_title'))
            ->assertSee(__('messages.health.product_has_warning'))
            ->assertSee('dị ứng', false);
    }

    public function test_a_pregnant_customer_is_referred_instead_of_being_sold_an_nsaid(): void
    {
        $this->stock('Ibuprofen 400mg', 'Ibuprofen 400mg', 'Giảm đau, hạ sốt, chống viêm', 200);

        UserHealthProfile::create([
            'MaKH' => $this->user->MaKH,
            'is_pregnant' => true,
            'consented_at' => now(),
        ]);

        $session = $this->runWizard($this->headacheAnswers());

        // The red flag outranks the self-care rule, so no medicine is offered at all.
        $this->assertNotSame('self_care', $session->outcome);
        $this->assertSame([], $session->suggested_products);
    }

    public function test_a_clean_profile_is_told_the_check_ran(): void
    {
        $this->stock('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau, hạ sốt', 100);

        UserHealthProfile::create([
            'MaKH' => $this->user->MaKH,
            'allergies' => ['penicillin'],
            'consented_at' => now(),
        ]);

        $session = $this->runWizard($this->headacheAnswers());

        // Silence would read as "not checked".
        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.safety_check_clear'))
            ->assertDontSee(__('messages.health.product_has_warning'));
    }

    public function test_a_customer_without_a_profile_is_invited_to_create_one(): void
    {
        $this->stock('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau, hạ sốt', 100);

        $session = $this->runWizard($this->headacheAnswers());

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.safety_check_no_profile'))
            ->assertSee(route('profile.edit'), false);
    }

    public function test_the_page_states_the_facts_the_rules_used(): void
    {
        $this->stock('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau, hạ sốt', 100);

        $session = $this->runWizard($this->headacheAnswers());

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        // The recorded facts live in the analysis block (score + contribution bars)
        // inside the basis tab; the symptom name must be Vietnamese, never the code.
        $this->assertStringContainsString(__('messages.health.analysis_title'), $content);
        $this->assertStringContainsString(__('messages.health.analysis_factor_symptoms'), $content);
        $this->assertStringContainsString(__('messages.health.analysis_factor_duration'), $content);
        $this->assertStringContainsString('đau đầu', $content);
        $this->assertStringNotContainsString('headache', $content);
        // "No red flag" is why self-care was allowed, so it is stated.
        $this->assertStringContainsString(__('messages.health.basis_red_flags_cleared'), $content);
    }

    public function test_a_triggered_red_flag_is_named_in_the_basis(): void
    {
        $session = $this->runWizard([1 => 'tôi bị đau ngực dữ dội']);

        $this->assertSame('emergency', $session->outcome);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.basis_red_flags_found'))
            ->assertDontSee(__('messages.health.basis_red_flags_cleared'));
    }

    public function test_the_dosage_line_is_not_truncated(): void
    {
        $usage = 'Giảm đau, hạ sốt. Uống 1-2 viên mỗi 4-6 giờ, tối đa 8 viên trong 24 giờ, không dùng quá 3 ngày liên tục nếu chưa hỏi dược sĩ.';
        $this->stock('Paracetamol 500mg', 'Paracetamol 500mg', $usage, 100);

        $session = $this->runWizard($this->headacheAnswers());

        // The dosage is the part the customer needs; a 90-character cut removed it.
        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee($usage, false);
    }

    public function test_a_referral_outcome_still_offers_no_products(): void
    {
        $this->stock('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau, hạ sốt', 100);

        $session = $this->runWizard([
            1 => 'bé 1 tuổi bị sốt cao',
            2 => '2-3 ngày',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $this->assertContains($session->outcome, ['refer_doctor', 'emergency']);
        $this->assertSame([], $session->suggested_products);

        // The "we found nothing in stock" wording must not appear where the decision
        // was "no medicine, see a human".
        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.no_products'))
            ->assertDontSee(__('messages.health.products_empty'));
    }

    public function test_an_expired_or_out_of_stock_match_is_still_excluded(): void
    {
        $fresh = $this->stock('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau, hạ sốt', 100);
        $empty = $this->stock('Hapacol 650mg', 'Paracetamol 650mg', 'Giảm đau, hạ sốt', 0);
        $expired = $this->stock('Efferalgan 500mg', 'Paracetamol 500mg', 'Giảm đau, hạ sốt', 100);
        $expired->forceFill(['expiration_date' => now()->subDay()])->save();

        $session = $this->runWizard($this->headacheAnswers());
        $ids = collect($session->suggested_products)->pluck('id');

        $this->assertTrue($ids->contains($fresh->MaSP));
        $this->assertFalse($ids->contains($empty->MaSP));
        $this->assertFalse($ids->contains($expired->MaSP));
    }

    public function test_a_symptom_with_no_catalog_query_suggests_nothing(): void
    {
        MedicalSymptom::where('code', 'headache')->update(['catalog_query' => null]);
        MedicalTriageRule::whereIn(
            'MaTC',
            MedicalSymptom::where('code', 'headache')->pluck('MaTC')
        )->update(['suggest_categories' => []]);

        $this->stock('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau, hạ sốt', 100);

        $session = $this->runWizard($this->headacheAnswers());

        $this->assertSame([], $session->suggested_products);
    }

    public function test_at_most_four_products_are_offered(): void
    {
        foreach (range(1, 8) as $i) {
            $this->stock("Paracetamol {$i}", 'Paracetamol 500mg', 'Giảm đau, hạ sốt', 100 - $i);
        }

        $session = $this->runWizard($this->headacheAnswers());

        $this->assertLessThanOrEqual(4, count($session->suggested_products));
    }

    public function test_the_follow_up_answers_are_grouped_separately_on_the_printout(): void
    {
        $this->stock('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau, hạ sốt', 100);

        $session = $this->runWizard($this->headacheAnswers());

        $session->forceFill([
            'collected_data' => array_merge($session->collected_data, [
                'ai_clarify_answers' => [
                    ['round' => 1, 'question' => 'Cơn đau âm ỉ hay dữ dội ạ?', 'answer' => 'âm ỉ'],
                ],
            ]),
        ])->save();

        // Grouped under its own heading so it is not read as a sixth wizard step.
        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.answers_clarify_title'))
            ->assertSee('Cơn đau âm ỉ hay dữ dội ạ?', false)
            ->assertSee('âm ỉ', false);
    }
}
