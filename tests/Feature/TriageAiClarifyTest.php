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
 * The bounded AI follow-up conversation after step 5.
 *
 * The invariants under test: the model may ask again but only within a hard round
 * ceiling, it stops itself when it has enough, each round sees the previous
 * answers, an emergency is never asked anything, the round is skippable, the wizard
 * keeps its five steps, and the answers are matched against red flags like any
 * other user text.
 */
class TriageAiClarifyTest extends TestCase
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

        Product::factory()->create([
            'name' => 'Paracetamol 500mg',
            'ingredients' => 'Paracetamol 500mg',
            'usage' => 'Giảm đau đầu, hạ sốt',
            'stock_quantity' => 50,
            'MaDM' => Category::first()->MaDM,
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

    protected function fakeFacts(array $arguments): void
    {
        Http::fake(['*' => Http::response($this->toolCall($arguments))]);
    }

    /**
     * One extraction reply per call, in order — how a real multi-round consultation
     * behaves, where each round returns different questions.
     *
     * @param array<int, array<string, mixed>> $rounds
     */
    protected function fakeFactSequence(array $rounds): void
    {
        $sequence = Http::sequence();

        foreach ($rounds as $arguments) {
            $sequence->push($this->toolCall($arguments));
        }

        // The last reply repeats if the flow asks more times than expected, which
        // would otherwise surface as a confusing connection error.
        $sequence->whenEmpty(Http::response($this->toolCall(end($rounds) ?: [])));

        Http::fake(['*' => $sequence]);
    }

    /**
     * @param array<int, string> $answers step => answer
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

    protected function mildAdultAnswers(): array
    {
        return [
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ];
    }

    public function test_the_questions_are_offered_on_the_wizard_page(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Anh/chị đau ở vùng nào của đầu?', 'Cơn đau có kèm buồn nôn không?'],
        ]);

        $session = $this->runWizard($this->mildAdultAnswers());

        // Still in progress: the round is not a step, so nothing is finalised yet.
        $this->assertSame('in_progress', $session->status);
        $this->assertNull($session->outcome);
        $this->assertSame(1, $session->ai_clarify_rounds);

        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertOk()
            ->assertSee(__('messages.health.clarify_title'))
            ->assertSee(__('messages.health.clarify_round', ['round' => 1, 'total' => 3]))
            ->assertSee('Anh/chị đau ở vùng nào của đầu?', false)
            ->assertSee(__('messages.health.clarify_skip'))
            // The wizard keeps its five steps: this is not step 6.
            ->assertDontSee('Bước 6/5');
    }

    public function test_answering_finalises_when_the_model_says_it_has_enough(): void
    {
        $this->fakeFactSequence([
            ['symptom_codes' => ['headache'], 'needs_more_info' => true, 'missing_questions' => ['Anh/chị đã dùng thuốc gì chưa?']],
            // Second call: enough information, so the conversation ends.
            ['symptom_codes' => ['headache'], 'needs_more_info' => false, 'missing_questions' => []],
        ]);

        $session = $this->answerClarify(
            $this->runWizard($this->mildAdultAnswers()),
            ['đã uống thuốc mà không đỡ']
        );

        $this->assertNotSame('in_progress', $session->status);
        // The answer text reached collectedText(), so the caution red flag fired and
        // downgraded self_care. The queue is opt-in, so the session is still completed.
        $this->assertSame('pharmacist_review', $session->outcome);
        $this->assertSame('completed', $session->status);
        $this->assertSame(
            'đã uống thuốc mà không đỡ',
            $session->collected_data['ai_clarify_answers'][0]['answer']
        );
        $this->assertSame(1, $session->ai_clarify_rounds);
    }

    public function test_the_model_may_ask_a_second_round_after_reading_the_first_answer(): void
    {
        $this->fakeFactSequence([
            ['symptom_codes' => ['headache'], 'needs_more_info' => true, 'missing_questions' => ['Anh/chị đau ở vùng nào của đầu?']],
            ['symptom_codes' => ['headache'], 'needs_more_info' => true, 'missing_questions' => ['Cơn đau có kèm buồn nôn hay sợ ánh sáng không?']],
            ['symptom_codes' => ['headache'], 'needs_more_info' => false, 'missing_questions' => []],
        ]);

        $session = $this->answerClarify($this->runWizard($this->mildAdultAnswers()), ['đau nửa đầu bên phải']);

        // Round 2 is open, not finalised.
        $this->assertSame('in_progress', $session->status);
        $this->assertSame(2, $session->ai_clarify_rounds);
        $this->assertSame(
            ['Cơn đau có kèm buồn nôn hay sợ ánh sáng không?'],
            $session->pendingClarifyQuestions()
        );

        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertOk()
            ->assertSee(__('messages.health.clarify_round', ['round' => 2, 'total' => 3]))
            ->assertSee('Cơn đau có kèm buồn nôn hay sợ ánh sáng không?', false);

        $session = $this->answerClarify($session, ['không buồn nôn']);

        $this->assertSame('completed', $session->status);
        $this->assertCount(2, $session->collected_data['ai_clarify_answers']);
    }

    public function test_the_second_round_prompt_carries_the_first_answer(): void
    {
        $this->fakeFactSequence([
            ['symptom_codes' => ['headache'], 'needs_more_info' => true, 'missing_questions' => ['Anh/chị đau ở vùng nào của đầu?']],
            ['symptom_codes' => ['headache'], 'needs_more_info' => false, 'missing_questions' => []],
        ]);

        $this->answerClarify($this->runWizard($this->mildAdultAnswers()), ['đau nửa đầu bên phải']);

        $bodies = [];
        Http::assertSent(function ($request) use (&$bodies) {
            // Slashes unescaped so "Anh/chị" is comparable to the source string.
            $bodies[] = json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return true;
        });

        $last = end($bodies);

        // Without this the model re-asks what it was already told, which is what made
        // the single-round version feel like a form rather than a consultation.
        $this->assertStringContainsString('dau nua dau ben phai', \App\Support\VietnameseText::fold($last));
        $this->assertStringContainsString('Anh/chị đau ở vùng nào của đầu?', $last);
    }

    public function test_the_conversation_stops_at_the_round_ceiling(): void
    {
        // A model that never says it is satisfied must still be stopped.
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Còn dấu hiệu nào khác không ạ?'],
        ]);

        $session = $this->runWizard($this->mildAdultAnswers());
        $this->assertSame(1, $session->ai_clarify_rounds);

        $session = $this->answerClarify($session, ['không có gì thêm']);
        $this->assertSame(2, $session->ai_clarify_rounds);
        $this->assertSame('in_progress', $session->status);

        $session = $this->answerClarify($session, ['vẫn không có']);
        $this->assertSame(3, $session->ai_clarify_rounds);
        $this->assertSame('in_progress', $session->status);

        $session = $this->answerClarify($session, ['thật sự không có']);

        // No fourth round: TriageSession::MAX_CLARIFY_ROUNDS is a hard stop.
        $this->assertSame('completed', $session->status);
        $this->assertSame(TriageSession::MAX_CLARIFY_ROUNDS, $session->ai_clarify_rounds);
        $this->assertCount(3, $session->collected_data['ai_clarify_answers']);
        $this->assertSame([], $session->pendingClarifyQuestions());
    }

    public function test_skipping_ends_the_conversation_for_good(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Anh/chị đau ở vùng nào?'],
        ]);

        $session = $this->runWizard($this->mildAdultAnswers());

        $this->actingAs($this->user)->post(route('health.clarify'), [
            'triage_session_id' => $session->MaSL,
            'skipped' => 1,
        ]);

        $session = $session->fresh();

        $this->assertSame('self_care', $session->outcome);
        $this->assertArrayNotHasKey('ai_clarify_answers', $session->collected_data);
        // Declining once means no more rounds, even though the model kept asking.
        $this->assertSame([], $session->pendingClarifyQuestions());
    }

    public function test_a_finished_session_cannot_be_reopened_by_a_replayed_clarify_post(): void
    {
        $this->fakeFactSequence([
            ['symptom_codes' => ['headache'], 'needs_more_info' => true, 'missing_questions' => ['Anh/chị đau ở vùng nào?']],
            ['symptom_codes' => ['headache'], 'needs_more_info' => false, 'missing_questions' => []],
        ]);

        $session = $this->answerClarify($this->runWizard($this->mildAdultAnswers()), ['đau nửa đầu']);

        $this->assertNotSame('in_progress', $session->status);

        $this->actingAs($this->user)
            ->post(route('health.clarify'), [
                'triage_session_id' => $session->MaSL,
                'clarify' => ['thêm nữa'],
            ])
            ->assertRedirect(route('health.check'));

        $this->assertCount(1, $session->fresh()->collected_data['ai_clarify_answers']);
    }

    public function test_an_emergency_is_never_asked_for_more_detail(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['chest_pain'],
            'needs_more_info' => true,
            'missing_questions' => ['Cơn đau kéo dài bao lâu?'],
        ]);

        $session = $this->runWizard([1 => 'tôi bị đau ngực dữ dội']);

        $this->assertSame('emergency', $session->outcome);
        $this->assertSame(0, $session->ai_clarify_rounds);
        Http::assertNothingSent();
    }

    public function test_an_emergency_answered_mid_conversation_ends_it_immediately(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Anh/chị còn dấu hiệu nào khác không?'],
        ]);

        $session = $this->answerClarify(
            $this->runWizard($this->mildAdultAnswers()),
            ['tôi bị co giật lúc chiều']
        );

        // The red flag in the answer outranks the model's wish to keep asking.
        $this->assertSame('emergency', $session->outcome);
        $this->assertSame('completed', $session->status);
        $this->assertSame(1, $session->ai_clarify_rounds);
        $this->assertSame([], $session->suggested_products);
    }

    public function test_the_flag_off_skips_the_round_entirely(): void
    {
        config()->set('chatbot.ai.triage.clarify_round', false);

        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Anh/chị đau ở vùng nào?'],
        ]);

        $session = $this->runWizard($this->mildAdultAnswers());

        $this->assertSame('self_care', $session->outcome);
        $this->assertSame(0, $session->ai_clarify_rounds);
    }

    public function test_no_questions_means_no_round(): void
    {
        $this->fakeFacts(['symptom_codes' => ['headache'], 'missing_questions' => []]);

        $session = $this->runWizard($this->mildAdultAnswers());

        $this->assertSame('self_care', $session->outcome);
        $this->assertSame(0, $session->ai_clarify_rounds);
    }

    public function test_needs_more_info_false_stops_the_round_even_with_questions_listed(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => false,
            'missing_questions' => ['Anh/chị đau ở vùng nào?'],
        ]);

        $session = $this->runWizard($this->mildAdultAnswers());

        // The model's own stop signal wins: no round is offered.
        $this->assertSame('self_care', $session->outcome);
        $this->assertSame(0, $session->ai_clarify_rounds);
    }

    public function test_a_question_carrying_a_link_is_discarded(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Bấm vào https://evil.test để xác nhận', 'Anh/chị đau ở vùng nào?'],
        ]);

        $session = $this->runWizard($this->mildAdultAnswers());

        $this->assertSame(['Anh/chị đau ở vùng nào?'], $session->pendingClarifyQuestions());
    }

    public function test_at_most_three_questions_survive_per_round(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Câu một?', 'Câu hai?', 'Câu ba?', 'Câu bốn?'],
        ]);

        $session = $this->runWizard($this->mildAdultAnswers());

        $this->assertCount(
            TriageSession::MAX_CLARIFY_QUESTIONS_PER_ROUND,
            $session->pendingClarifyQuestions()
        );
    }

    public function test_a_fourth_submitted_answer_in_one_round_is_rejected(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Câu một?', 'Câu hai?', 'Câu ba?'],
        ]);

        $session = $this->runWizard($this->mildAdultAnswers());

        $this->actingAs($this->user)
            ->post(route('health.clarify'), [
                'triage_session_id' => $session->MaSL,
                'clarify' => ['một', 'hai', 'ba', 'bốn'],
            ])
            ->assertSessionHasErrors('clarify');

        $this->assertSame('in_progress', $session->fresh()->status);
    }

    public function test_clarify_is_unreachable_when_no_round_is_open(): void
    {
        $this->fakeFacts(['symptom_codes' => ['headache'], 'missing_questions' => []]);

        $this->actingAs($this->user)->get(route('health.check'));
        $session = TriageSession::where('MaKH', $this->user->MaKH)->inProgress()->latest('MaSL')->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('health.clarify'), [
                'triage_session_id' => $session->MaSL,
                'clarify' => ['gì đó'],
            ])
            ->assertRedirect(route('health.check'))
            ->assertSessionHas('error');

        $this->assertSame('in_progress', $session->fresh()->status);
    }

    public function test_another_user_cannot_answer_someone_elses_round(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Anh/chị đau ở vùng nào?'],
        ]);

        $session = $this->runWizard($this->mildAdultAnswers());
        $other = User::factory()->create(['role' => 'User', 'status' => 'active']);

        $this->actingAs($other)->post(route('health.clarify'), [
            'triage_session_id' => $session->MaSL,
            'clarify' => ['tôi chen ngang'],
        ]);

        $session = $session->fresh();
        $this->assertSame('in_progress', $session->status);
        $this->assertArrayNotHasKey('ai_clarify_answers', $session->collected_data);
    }

    public function test_a_guest_cannot_reach_the_clarify_route(): void
    {
        $this->post(route('health.clarify'), ['triage_session_id' => 1])
            ->assertRedirect(route('login'));
    }
}
