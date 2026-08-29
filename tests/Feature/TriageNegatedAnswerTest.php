<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\TriageSession;
use App\Models\User;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Answering "no" to a danger-sign question must not raise an emergency.
 *
 * The multi-round follow-up asks about red flags by name, so the answers are full of
 * red-flag keywords in the negative. This was a live false positive: a customer with
 * a tension headache who answered "không sốt, không nôn, không cứng cổ" was told to
 * call 115 for suspected meningitis.
 *
 * The reverse case is tested just as hard: a real danger sign mentioned anywhere in a
 * follow-up answer must still escalate.
 */
class TriageNegatedAnswerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Category::create(['name' => 'Thuốc giảm đau - Hạ sốt', 'description' => 'Giảm đau, hạ sốt']);
        $this->seed(MedicalKnowledgeSeeder::class);

        config()->set('chatbot.ai.triage.fact_extraction', true);
        config()->set('chatbot.ai.triage.clarify_round', true);

        $this->user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
            'birth_date' => now()->subYears(34)->toDateString(),
        ]);

        Product::create([
            'MaDM' => Category::first()->MaDM,
            'name' => 'Paracetamol 500mg',
            'ingredients' => 'Paracetamol 500mg',
            'usage' => 'Giảm đau, hạ sốt. Uống 1-2 viên mỗi 4-6 giờ',
            'price' => 5000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 5,
            'batch_number' => 'B-' . uniqid(),
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    protected function toolCall(array $arguments): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'function_call' => [
                        'name' => 'extract_triage_facts',
                        'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE),
                    ],
                ],
            ]],
        ];
    }

    /**
     * Offer exactly one follow-up round, then stop.
     *
     * The first extraction must say `needs_more_info` so the round is actually shown;
     * the second must say it has enough, or the session never finalises and every
     * assertion below would pass vacuously.
     */
    protected function fakeOneRound(string $question): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->toolCall([
                'symptom_codes' => ['headache'],
                'needs_more_info' => true,
                'missing_questions' => [$question],
            ]))
            ->push($this->toolCall([
                'symptom_codes' => ['headache'],
                'needs_more_info' => false,
                'missing_questions' => [],
            ]))
            ->whenEmpty(Http::response($this->toolCall([
                'symptom_codes' => ['headache'],
                'needs_more_info' => false,
                'missing_questions' => [],
            ]))),
        ]);
    }

    protected function runWizard(): TriageSession
    {
        $this->actingAs($this->user)->get(route('health.check'));
        $session = TriageSession::where('MaKH', $this->user->MaKH)->inProgress()->latest('MaSL')->firstOrFail();

        foreach ([1 => 'tôi bị đau đầu', 2 => 'hôm nay', 3 => 'chưa dùng gì', 4 => 'không có', 5 => 'không có thêm'] as $step => $answer) {
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

    /**
     * @param array<int, string> $answers
     */
    protected function answerClarify(TriageSession $session, array $answers): TriageSession
    {
        $this->actingAs($this->user)->post(route('health.clarify'), [
            'triage_session_id' => $session->MaSL,
            'clarify' => $answers,
        ]);

        return $session->fresh();
    }

    public function test_denying_every_danger_sign_does_not_trigger_an_emergency(): void
    {
        // The exact question the model asks, and the exact answer a healthy patient
        // gives. Every red-flag keyword is present, all negated.
        $this->fakeOneRound('Anh/chị có kèm sốt, nôn hay cứng cổ không ạ?');

        $session = $this->runWizard();
        $session = $this->answerClarify($session, ['không sốt, không nôn, không cứng cổ']);

        $this->assertSame('self_care', $session->outcome);
        $this->assertSame([], $session->triggered_red_flags);
        $this->assertNotEmpty($session->suggested_products);
    }

    public function test_denying_neurological_signs_does_not_trigger_a_referral(): void
    {
        $this->fakeOneRound('Anh/chị có mờ mắt, yếu tay chân hay nói khó không ạ?');

        $session = $this->runWizard();
        $session = $this->answerClarify($session, ['không, mắt bình thường, tay chân bình thường']);

        $this->assertSame('self_care', $session->outcome);
    }

    public function test_a_real_danger_sign_in_a_follow_up_answer_still_escalates(): void
    {
        $this->fakeOneRound('Anh/chị có kèm dấu hiệu nào khác không ạ?');

        $session = $this->runWizard();
        $session = $this->answerClarify($session, ['tôi bị co giật lúc chiều']);

        $this->assertSame('emergency', $session->outcome);
        $this->assertSame([], $session->suggested_products);
    }

    public function test_a_mixed_answer_keeps_the_affirmed_sign(): void
    {
        $this->fakeOneRound('Anh/chị có kèm sốt, nôn hay cứng cổ không ạ?');

        $session = $this->runWizard();
        // Denies two, reports one. The reported one must win: a negation only reaches
        // the phrase directly after it, never the rest of the sentence.
        $session = $this->answerClarify($session, ['không sốt, không nôn, nhưng bị cứng cổ']);

        $this->assertNotSame('self_care', $session->outcome);
    }

    public function test_a_negation_far_from_the_symptom_is_not_treated_as_a_denial(): void
    {
        $this->fakeOneRound('Cơn đau thế nào ạ?');

        $session = $this->runWizard();
        // "không" belongs to "không chịu được", not to the chest pain.
        $session = $this->answerClarify($session, ['tôi không chịu được, còn bị đau ngực dữ dội nữa']);

        $this->assertSame('emergency', $session->outcome);
    }

    public function test_saying_no_medication_was_tried_is_not_a_caution_flag(): void
    {
        $this->fakeOneRound('Anh/chị đã dùng thuốc gì chưa ạ?');

        $session = $this->runWizard();
        $session = $this->answerClarify($session, ['chưa uống thuốc gì cả']);

        // "đã dùng thuốc mà không đỡ" is a caution flag; "chưa dùng" is not.
        $this->assertSame('self_care', $session->outcome);
    }

    public function test_medication_that_did_not_help_is_still_a_caution_flag(): void
    {
        $this->fakeOneRound('Anh/chị đã dùng thuốc gì chưa ạ?');

        $session = $this->runWizard();
        $session = $this->answerClarify($session, ['uống paracetamol mà không đỡ']);

        // The keyword is itself built around "không", so the negation check must not
        // cancel it.
        $this->assertSame('pharmacist_review', $session->outcome);
    }
}
