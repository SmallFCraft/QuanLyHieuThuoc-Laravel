<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\TriageSession;
use App\Models\User;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A danger sign confirmed by name in a follow-up round must escalate.
 *
 * The live failure this exists for (MaSL=55): a 20-year-old with COVID answered "Có" to
 * "Anh/chị có sốt, khó thở hoặc đau ngực không?", then confirmed dyspnoea at rest and
 * continuous chest pain over two more rounds — and the result page said "Em không thấy
 * dấu hiệu nguy hiểm nào". collectedText() stored only the ANSWERS, so the haystack held
 * "co ca luon nhe ... co co khong" and contained neither `kho tho` nor `dau nguc`. No
 * text rule could fire.
 *
 * The fix must not swing the other way. Folding the QUESTION into the haystack turns
 * every denial into an emergency — fold("...đau ngực không?" . " Không") affirms every
 * keyword in it — which is the exact false positive TriageNegatedAnswerTest owns. So no
 * question text ever reaches the matcher: an affirmed pair yields the FLAG whose linked
 * symptom the question named, matched on that symptom's canonical name in isolation.
 *
 * Every assertion that something did NOT escalate is therefore load-bearing.
 */
class TriageAffirmedClarifyTest extends TestCase
{
    use RefreshDatabase;

    /** The real round-1 question from session 55. */
    protected const MULTI_SIGN_QUESTION = 'Anh/chị có sốt, khó thở hoặc đau ngực không?';

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
            'birth_date' => now()->subYears(20)->toDateString(),
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
     * One follow-up round, then stop.
     *
     * The extractor deliberately returns only `cough` — never `shortness_breath` or
     * `chest_pain` — so nothing in these tests can escalate via the AI-code tier. What
     * is under test is the deterministic affirmation path alone.
     *
     * @param array<int, string> $questions
     */
    protected function fakeOneRound(array $questions): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->toolCall([
                'symptom_codes' => ['cough'],
                'needs_more_info' => true,
                'missing_questions' => $questions,
            ]))
            ->whenEmpty(Http::response($this->toolCall([
                'symptom_codes' => ['cough'],
                'needs_more_info' => false,
                'missing_questions' => [],
            ]))),
        ]);
    }

    /**
     * @param array<int, string> $wizardAnswers
     */
    protected function runWizard(array $wizardAnswers = []): TriageSession
    {
        $answers = $wizardAnswers !== [] ? $wizardAnswers : [
            1 => 'dính covid , và ho rất nhiều',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ];

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

    /**
     * @return array<int, string>
     */
    protected function flagCodes(TriageSession $session): array
    {
        return collect($session->triggered_red_flags)->pluck('code')->all();
    }

    public function test_affirming_a_multi_sign_question_escalates_to_emergency(): void
    {
        $this->fakeOneRound([self::MULTI_SIGN_QUESTION]);

        $session = $this->answerClarify($this->runWizard(), ['Có']);

        $this->assertSame('emergency', $session->outcome);
        $this->assertContains('chest_pain_severe', $this->flagCodes($session));
        $this->assertContains('breathing_difficulty', $this->flagCodes($session));
        $this->assertSame([], $session->suggested_products);
        $this->assertStringContainsString('115', (string) $session->advice_given);
    }

    public function test_denying_the_same_multi_sign_question_stays_self_care(): void
    {
        // The test that must never be weakened. The question names three danger signs;
        // the patient denies them. If this ever escalates, the fix has become the bug
        // TriageNegatedAnswerTest was written to kill.
        foreach (['không', 'Không có', 'không sốt, không nôn, không cứng cổ', 'Chưa đo'] as $denial) {
            $this->fakeOneRound([self::MULTI_SIGN_QUESTION]);

            $session = $this->answerClarify($this->runWizard(), [$denial]);

            $this->assertNotSame('emergency', $session->outcome, $denial);
            $this->assertSame([], $this->flagCodes($session), $denial);

            TriageSession::where('MaKH', $this->user->MaKH)->delete();
        }
    }

    public function test_a_partly_denied_answer_keeps_only_the_undenied_sign(): void
    {
        $this->fakeOneRound([self::MULTI_SIGN_QUESTION]);

        // The contrast marker keeps the yes alive; the patient's own words then veto the
        // one sign they named. Escalating on the rest is the point — "nhưng không khó thở"
        // says nothing about the chest pain.
        $session = $this->answerClarify($this->runWizard(), ['Có, nhưng không khó thở']);

        $this->assertContains('chest_pain_severe', $this->flagCodes($session));
        $this->assertNotContains('breathing_difficulty', $this->flagCodes($session));
    }

    /**
     * Answers that read as a yes word-by-word but are not one, driven end-to-end.
     *
     * Every one of these was measured reaching `emergency` for a patient with a mild
     * cough before the guards in VietnameseText::isAffirmation() existed.
     *
     * @return array<string, array{0: string}>
     */
    public static function falseYesProvider(): array
    {
        return [
            'retracted behind pronouns' => ['Vâng, em không có triệu chứng nào cả'],
            'stacked politeness' => ['Dạ vâng, em không bị gì'],
            'yes-particle then negation' => ['Đúng rồi, em không bị mấy cái đó'],
            'co gi dau idiom' => ['Có gì đâu ạ'],
            'reassurance' => ['Vâng em khỏe, không sao'],
            'no longer present' => ['Có hồi hôm qua, nay không còn'],
            'resolved' => ['Có đỡ rồi ạ'],
            'resolved explicitly' => ['Dạ có, hết rồi ạ'],
            'years ago' => ['Có, hồi 5 năm trước'],
            // "dùng" and "đúng" both fold to "dung".
            'dung as a verb' => ['Đã dùng thuốc nhưng không đỡ'],
        ];
    }

    #[DataProvider('falseYesProvider')]
    public function test_an_answer_that_only_looks_like_a_yes_does_not_escalate(string $answer): void
    {
        $this->fakeOneRound([self::MULTI_SIGN_QUESTION]);

        $session = $this->answerClarify($this->runWizard(), [$answer]);

        $this->assertNotSame('emergency', $session->outcome, $answer);
        $this->assertNotContains('chest_pain_severe', $this->flagCodes($session), $answer);
        $this->assertNotContains('breathing_difficulty', $this->flagCodes($session), $answer);
    }

    /**
     * A negation in one answer must not reach into the next.
     *
     * Wizard step 4 "Không có" and step 5 "khó thở nhiều lắm" used to be joined into one
     * haystack, and mentionsAffirmatively()'s backward window — which cannot know the two
     * words came from different questions — read the adjacent "khong co" as cancelling a
     * real emergency. Each answer is now matched as its own utterance.
     *
     * @return array<string, array{0: array<int, string>, 1: string}>
     */
    public static function crossAnswerBleedProvider(): array
    {
        return [
            'denial then dyspnoea' => [
                [1 => 'tôi bị ho', 2 => 'hôm nay', 3 => 'chưa dùng gì', 4 => 'Không có', 5 => 'khó thở nhiều lắm'],
                'breathing_difficulty',
            ],
            'denial then chest pain' => [
                [1 => 'tôi bị ho', 2 => 'hôm nay', 3 => 'chưa dùng gì', 4 => 'Không có', 5 => 'đau ngực dữ dội'],
                'chest_pain_severe',
            ],
            'danger sign then a denial after it' => [
                [1 => 'đau bụng', 2 => 'hôm nay', 3 => 'chưa', 4 => 'nôn ra máu', 5 => 'không có thêm'],
                'vomiting_blood',
            ],
        ];
    }

    /**
     * @param array<int, string> $answers
     */
    #[DataProvider('crossAnswerBleedProvider')]
    public function test_a_denial_in_one_answer_does_not_cancel_the_next(array $answers, string $expectedFlag): void
    {
        config()->set('chatbot.ai.triage.fact_extraction', false);
        config()->set('chatbot.ai.triage.clarify_round', false);

        $session = $this->runWizard($answers);

        Http::assertNothingSent();
        $this->assertSame('emergency', $session->outcome);
        $this->assertContains($expectedFlag, $this->flagCodes($session));
    }

    public function test_a_restrictive_answer_does_not_project(): void
    {
        $this->fakeOneRound([self::MULTI_SIGN_QUESTION]);

        // "có ho thôi" is a yes about the cough, not about the three danger signs.
        $session = $this->answerClarify($this->runWizard(), ['có ho thôi']);

        $this->assertNotSame('emergency', $session->outcome);
        $this->assertSame([], $this->flagCodes($session));
    }

    public function test_a_degree_qualified_flag_does_not_fire_from_an_affirmation(): void
    {
        // Plain fever is not "sốt cao kéo dài". The discriminator is the flag's own
        // keyword list: high_fever_persistent never lists the bare symptom name, so a
        // confirmed "có sốt" cannot reach it.
        $this->fakeOneRound(['Anh/chị có sốt không?']);

        $session = $this->answerClarify($this->runWizard(), ['Có']);

        $this->assertNotContains('high_fever_persistent', $this->flagCodes($session));
        $this->assertNotSame('emergency', $session->outcome);
    }

    public function test_the_projection_is_order_independent(): void
    {
        // Regression guard for a rejected implementation: appending the projected terms
        // to the patient's text made detection order-dependent, because collectedText()
        // ends with "... không có thêm" and an adjacent trailing negation cancelled the
        // first projected term. The projection is matched as its own haystack instead.
        $this->fakeOneRound([self::MULTI_SIGN_QUESTION, 'Anh/chị có bệnh nền không?']);

        $session = $this->answerClarify($this->runWizard(), ['Có', 'không']);

        $this->assertContains('chest_pain_severe', $this->flagCodes($session));
        $this->assertContains('breathing_difficulty', $this->flagCodes($session));
    }

    public function test_the_projection_does_not_invent_matched_symptoms(): void
    {
        // matched_symptoms describes what the patient said; the projection yields FLAGS,
        // not symptom codes. The extractor was faked to return only `cough`.
        $this->fakeOneRound([self::MULTI_SIGN_QUESTION]);

        $session = $this->answerClarify($this->runWizard(), ['Có']);

        $this->assertNotContains('chest_pain', (array) $session->matched_symptoms);
        $this->assertNotContains('shortness_breath', (array) $session->matched_symptoms);
    }

    public function test_an_escalated_session_is_asked_no_further_round(): void
    {
        $this->fakeOneRound([self::MULTI_SIGN_QUESTION]);

        $session = $this->answerClarify($this->runWizard(), ['Có']);

        $this->assertSame('emergency', $session->outcome);
        $this->assertSame('completed', $session->status);
        $this->assertSame([], $session->pendingClarifyQuestions());
    }

    public function test_the_text_tier_still_works_with_the_ai_disabled(): void
    {
        // The affirmation tier is deterministic, but it needs a question to have been
        // asked. With extraction off there are no follow-up rounds at all, so the
        // patient's own words must still carry the emergency.
        config()->set('chatbot.ai.triage.fact_extraction', false);
        config()->set('chatbot.ai.triage.clarify_round', false);

        $session = $this->runWizard([
            1 => 'tôi bị đau ngực dữ dội và khó thở',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        Http::assertNothingSent();
        $this->assertSame('emergency', $session->outcome);
    }
}
