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
 * Red flags reached from the AI's structured symptom codes — the second net.
 *
 * Some answers carry no yes-word and no keyword at all. "Khi nghỉ và vận động" answers
 * "Anh/chị khó thở khi nghỉ hay chỉ khi vận động?" and means dyspnoea at rest, which is
 * an emergency; no deterministic classifier reads it. The extractor does, and returns
 * `shortness_breath`.
 *
 * Putting a model in the emergency path needs brakes, and there are three:
 *  - only DERIVABLE flags are reachable, i.e. flags whose own keyword list contains the
 *    linked symptom's plain name, so plain fever can never become "sốt cao kéo dài";
 *  - the flag's vocabulary must appear somewhere in the transcript, which means one of
 *    OUR questions named it — a hallucinated code anchors nowhere;
 *  - the patient's own denial always wins.
 *
 * Every assertion that something did NOT escalate is one of those brakes.
 */
class TriageCodeDerivedRedFlagTest extends TestCase
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
     * One follow-up round asking $questions, then a second extraction returning
     * $finalCodes — the codes that reach the derived-flag pass.
     *
     * @param array<int, string> $questions
     * @param array<int, string> $finalCodes
     */
    protected function fakeRound(array $questions, array $finalCodes): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->toolCall([
                'symptom_codes' => ['cough'],
                'needs_more_info' => true,
                'missing_questions' => $questions,
            ]))
            ->whenEmpty(Http::response($this->toolCall([
                'symptom_codes' => $finalCodes,
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

    public function test_an_ai_code_escalates_when_our_question_named_the_sign(): void
    {
        // The session-55 round-3 case: an either/or question that presupposes dyspnoea,
        // answered with a phrase that affirms nothing lexically.
        $this->fakeRound(
            ['Anh/chị khó thở khi nghỉ hay chỉ khi vận động?'],
            ['cough', 'shortness_breath']
        );

        $session = $this->answerClarify($this->runWizard(), ['Khi nghỉ và vận động']);

        $this->assertSame('emergency', $session->outcome);
        $this->assertContains('breathing_difficulty', $this->flagCodes($session));
        $this->assertSame([], $session->suggested_products);
    }

    public function test_a_denied_danger_sign_is_not_escalated_by_an_ai_code(): void
    {
        // The patient's words beat the extractor. This is the brake that matters most:
        // an over-eager model must not be able to escalate a denial.
        $this->fakeRound(
            ['Anh/chị có khó thở hoặc đau ngực không?'],
            ['cough', 'shortness_breath', 'chest_pain']
        );

        $session = $this->answerClarify($this->runWizard(), ['không khó thở, không đau ngực']);

        $this->assertNotSame('emergency', $session->outcome);
        $this->assertNotContains('breathing_difficulty', $this->flagCodes($session));
        $this->assertNotContains('chest_pain_severe', $this->flagCodes($session));
    }

    public function test_an_ungrounded_code_is_dropped(): void
    {
        // Nothing in the transcript — neither the patient's words nor our questions —
        // mentions chest pain, so a code claiming it anchors nowhere.
        $this->fakeRound(
            ['Cơn đau đầu của anh/chị âm ỉ hay dữ dội?'],
            ['headache', 'chest_pain']
        );

        $session = $this->answerClarify(
            $this->runWizard([
                1 => 'tôi bị đau đầu',
                2 => 'hôm nay',
                3 => 'chưa dùng gì',
                4 => 'không có',
                5 => 'không có thêm',
            ]),
            ['âm ỉ thôi']
        );

        $this->assertNotSame('emergency', $session->outcome);
        $this->assertNotContains('chest_pain_severe', $this->flagCodes($session));
    }

    public function test_an_alias_only_mention_is_not_anchored(): void
    {
        // "tức ngực" is a chest_pain alias but not a chest_pain_severe keyword, so it
        // describes discomfort rather than the emergency the flag names.
        $this->fakeRound(
            ['Anh/chị tức ngực khi nào?'],
            ['cough', 'chest_pain']
        );

        $session = $this->answerClarify(
            $this->runWizard([
                1 => 'hơi tức ngực khi ho',
                2 => 'hôm nay',
                3 => 'chưa dùng gì',
                4 => 'không có',
                5 => 'không có thêm',
            ]),
            ['khi ho mạnh']
        );

        $this->assertNotSame('emergency', $session->outcome);
        $this->assertNotContains('chest_pain_severe', $this->flagCodes($session));
    }

    public function test_a_plain_fever_code_never_becomes_a_high_fever_flag(): void
    {
        // The degree gap. high_fever_persistent's keywords are "sot cao", "sot 39",
        // "sot 40" — never the bare name — so it is not derivable from the code.
        $this->fakeRound(['Anh/chị sốt bao nhiêu độ?'], ['cough', 'fever']);

        $session = $this->answerClarify($this->runWizard(), ['chưa đo']);

        $this->assertNotContains('high_fever_persistent', $this->flagCodes($session));
        $this->assertNotContains('stiff_neck_fever', $this->flagCodes($session));
    }

    public function test_a_plain_diarrhea_code_never_becomes_severe_diarrhea(): void
    {
        $this->fakeRound(['Anh/chị tiêu chảy mấy lần một ngày?'], ['diarrhea']);

        $session = $this->answerClarify(
            $this->runWizard([
                1 => 'tôi bị tiêu chảy',
                2 => 'hôm nay',
                3 => 'chưa dùng gì',
                4 => 'không có',
                5 => 'không có thêm',
            ]),
            ['hai lần']
        );

        $this->assertNotContains('severe_diarrhea', $this->flagCodes($session));
    }

    /**
     * The veto isDeniedInText() cannot see.
     *
     * A bare "Không" denies the question's danger signs without naming any of them, so a
     * keyword veto has nothing to match — yet the extractor reads the question too and
     * returns the codes it mentions. Measured before the fix: a patient with "hơi tức
     * ngực khi ho" who answered "Không" to "có đau ngực dữ dội không?" was escalated to
     * emergency.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<int, string>}>
     */
    public static function bareDenialProvider(): array
    {
        return [
            'mild alias, our question names the severe sign, bare no' => [
                'hơi tức ngực khi ho',
                'Anh/chị có đau ngực dữ dội không?',
                'Không',
                ['cough', 'chest_pain'],
            ],
            'same, politeness before the no' => [
                'hơi tức ngực khi ho',
                'Anh/chị có đau ngực dữ dội không?',
                'Dạ không ạ',
                ['cough', 'chest_pain'],
            ],
            'hallucinated code, our question named it, no' => [
                'tôi bị đau đầu',
                'Anh/chị có đau ngực không?',
                'Không có',
                ['headache', 'chest_pain'],
            ],
        ];
    }

    #[DataProvider('bareDenialProvider')]
    public function test_a_bare_denial_vetoes_the_code_tier(
        string $complaint,
        string $question,
        string $answer,
        array $finalCodes
    ): void {
        $this->fakeRound([$question], $finalCodes);

        $session = $this->answerClarify(
            $this->runWizard([
                1 => $complaint,
                2 => 'hôm nay',
                3 => 'chưa dùng gì',
                4 => 'không có',
                5 => 'không có thêm',
            ]),
            [$answer]
        );

        $this->assertNotSame('emergency', $session->outcome, $answer);
        $this->assertNotContains('chest_pain_severe', $this->flagCodes($session), $answer);
    }

    public function test_a_non_committal_answer_does_not_veto(): void
    {
        // The mirror of the test above, and the reason the veto reads only explicit
        // denials: "Khi nghỉ và vận động" names no sign and denies nothing, and it is
        // exactly the answer this tier exists to catch.
        $this->fakeRound(
            ['Anh/chị khó thở khi nghỉ hay chỉ khi vận động?'],
            ['cough', 'shortness_breath']
        );

        $session = $this->answerClarify($this->runWizard(), ['Khi nghỉ và vận động']);

        $this->assertSame('emergency', $session->outcome);
        $this->assertContains('breathing_difficulty', $this->flagCodes($session));
    }

    public function test_a_derived_emergency_records_its_provenance(): void
    {
        $this->fakeRound(
            ['Anh/chị khó thở khi nghỉ hay chỉ khi vận động?'],
            ['cough', 'shortness_breath']
        );

        $session = $this->answerClarify($this->runWizard(), ['Khi nghỉ và vận động']);

        $this->assertSame('emergency', $session->outcome);
        // Which tier decided, recorded next to the codes the model proposed, so a later
        // review can tell a derived escalation from a text one.
        $this->assertContains('breathing_difficulty', (array) data_get($session->ai_meta, 'derived_red_flags'));
    }

    public function test_a_derived_emergency_asks_no_further_round(): void
    {
        $this->fakeRound(
            ['Anh/chị khó thở khi nghỉ hay chỉ khi vận động?'],
            ['cough', 'shortness_breath']
        );

        $session = $this->answerClarify($this->runWizard(), ['Khi nghỉ và vận động']);

        $this->assertSame('completed', $session->status);
        $this->assertSame([], $session->pendingClarifyQuestions());
    }
}
