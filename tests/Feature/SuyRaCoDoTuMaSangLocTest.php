<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use Database\Seeders\TriThucYTeSeeder;
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
class SuyRaCoDoTuMaSangLocTest extends TestCase
{
    use RefreshDatabase;

    protected NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt', 'mo_ta' => 'Giảm đau, hạ sốt']);
        $this->seed(TriThucYTeSeeder::class);

        config()->set('chatbot.ai.triage.fact_extraction', true);
        config()->set('chatbot.ai.triage.clarify_round', true);

        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active',
            'ngay_sinh' => now()->subYears(20)->toDateString(),
        ]);

        SanPham::create(['ma_dm' => DanhMuc::first()->ma_dm,
            'ten' => 'Paracetamol 500mg',
            'thanh_phan' => 'Paracetamol 500mg',
            'cach_dung' => 'Giảm đau, hạ sốt. Uống 1-2 viên mỗi 4-6 giờ',
            'gia' => 5000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 5,
            'so_lo' => 'B-' . uniqid(),
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
    protected function runWizard(array $wizardAnswers = []): PhienSangLoc
    {
        $answers = $wizardAnswers !== [] ? $wizardAnswers : [
            1 => 'dính covid , và ho rất nhiều',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ];

        $this->actingAs($this->nguoiDung)->get(route('sang-loc.kiem-tra'));
        $phien = PhienSangLoc::where('ma_nd', $this->nguoiDung->ma_nd)->inProgress()->latest('ma_sl')->firstOrFail();

        foreach ($answers as $step => $answer) {
            $current = PhienSangLoc::find($phien->ma_sl);

            if ($current === null || $current->status !== 'in_progress') {
                break;
            }

            $this->actingAs($this->nguoiDung)->post(route('sang-loc.tra-loi'), [
                'triage_session_id' => $phien->ma_sl,
                'step' => $step,
                'answer' => $answer,
            ]);
        }

        return $phien->fresh();
    }

    /**
     * @param array<int, string> $answers
     */
    protected function answerClarify(PhienSangLoc $phien, array $answers): PhienSangLoc
    {
        $this->actingAs($this->nguoiDung)->post(route('sang-loc.lam-ro'), [
            'triage_session_id' => $phien->ma_sl,
            'clarify' => $answers,
        ]);

        return $phien->fresh();
    }

    /**
     * @return array<int, string>
     */
    protected function flagCodes(PhienSangLoc $phien): array
    {
        return collect($phien->triggered_red_flags)->pluck('code')->all();
    }

    public function test_ma_ai_lam_tang_muc_khi_cau_hoi_cua_he_thong_neu_dau_hieu(): void
    {
        // The session-55 round-3 case: an either/or question that presupposes dyspnoea,
        // answered with a phrase that affirms nothing lexically.
        $this->fakeRound(
            ['Anh/chị khó thở khi nghỉ hay chỉ khi vận động?'],
            ['cough', 'shortness_breath']
        );

        $phien = $this->answerClarify($this->runWizard(), ['Khi nghỉ và vận động']);

        $this->assertSame('emergency', $phien->outcome);
        $this->assertContains('breathing_difficulty', $this->flagCodes($phien));
        $this->assertSame([], $phien->suggested_products);
    }

    public function test_dau_hieu_nguy_hiem_bi_phu_dinh_khong_bi_ma_ai_lam_tang_muc(): void
    {
        // The patient's words beat the extractor. This is the brake that matters most:
        // an over-eager model must not be able to escalate a denial.
        $this->fakeRound(
            ['Anh/chị có khó thở hoặc đau ngực không?'],
            ['cough', 'shortness_breath', 'chest_pain']
        );

        $phien = $this->answerClarify($this->runWizard(), ['không khó thở, không đau ngực']);

        $this->assertNotSame('emergency', $phien->outcome);
        $this->assertNotContains('breathing_difficulty', $this->flagCodes($phien));
        $this->assertNotContains('chest_pain_severe', $this->flagCodes($phien));
    }

    public function test_ma_khong_co_can_cu_bi_loai(): void
    {
        // Nothing in the transcript — neither the patient's words nor our questions —
        // mentions chest pain, so a code claiming it anchors nowhere.
        $this->fakeRound(
            ['Cơn đau đầu của anh/chị âm ỉ hay dữ dội?'],
            ['headache', 'chest_pain']
        );

        $phien = $this->answerClarify(
            $this->runWizard([
                1 => 'tôi bị đau đầu',
                2 => 'hôm nay',
                3 => 'chưa dùng gì',
                4 => 'không có',
                5 => 'không có thêm',
            ]),
            ['âm ỉ thôi']
        );

        $this->assertNotSame('emergency', $phien->outcome);
        $this->assertNotContains('chest_pain_severe', $this->flagCodes($phien));
    }

    public function test_chi_nhac_biet_danh_khong_duoc_xem_la_can_cu(): void
    {
        // "tức ngực" is a chest_pain alias but not a chest_pain_severe keyword, so it
        // describes discomfort rather than the emergency the flag names.
        $this->fakeRound(
            ['Anh/chị tức ngực khi nào?'],
            ['cough', 'chest_pain']
        );

        $phien = $this->answerClarify(
            $this->runWizard([
                1 => 'hơi tức ngực khi ho',
                2 => 'hôm nay',
                3 => 'chưa dùng gì',
                4 => 'không có',
                5 => 'không có thêm',
            ]),
            ['khi ho mạnh']
        );

        $this->assertNotSame('emergency', $phien->outcome);
        $this->assertNotContains('chest_pain_severe', $this->flagCodes($phien));
    }

    public function test_ma_sot_thong_thuong_khong_bao_gio_thanh_co_sot_cao(): void
    {
        // The degree gap. high_fever_persistent's keywords are "sot cao", "sot 39",
        // "sot 40" — never the bare name — so it is not derivable from the code.
        $this->fakeRound(['Anh/chị sốt bao nhiêu độ?'], ['cough', 'fever']);

        $phien = $this->answerClarify($this->runWizard(), ['chưa đo']);

        $this->assertNotContains('high_fever_persistent', $this->flagCodes($phien));
        $this->assertNotContains('stiff_neck_fever', $this->flagCodes($phien));
    }

    public function test_ma_tieu_chay_thong_thuong_khong_bao_gio_thanh_tieu_chay_nang(): void
    {
        $this->fakeRound(['Anh/chị tiêu chảy mấy lần một ngày?'], ['diarrhea']);

        $phien = $this->answerClarify(
            $this->runWizard([
                1 => 'tôi bị tiêu chảy',
                2 => 'hôm nay',
                3 => 'chưa dùng gì',
                4 => 'không có',
                5 => 'không có thêm',
            ]),
            ['hai lần']
        );

        $this->assertNotContains('severe_diarrhea', $this->flagCodes($phien));
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
    public function test_phu_dinh_don_thuan_phu_quyet_tang_ma(
        string $complaint,
        string $question,
        string $answer,
        array $finalCodes
    ): void {
        $this->fakeRound([$question], $finalCodes);

        $phien = $this->answerClarify(
            $this->runWizard([
                1 => $complaint,
                2 => 'hôm nay',
                3 => 'chưa dùng gì',
                4 => 'không có',
                5 => 'không có thêm',
            ]),
            [$answer]
        );

        $this->assertNotSame('emergency', $phien->outcome, $answer);
        $this->assertNotContains('chest_pain_severe', $this->flagCodes($phien), $answer);
    }

    public function test_cau_tra_loi_khong_cam_ket_khong_phu_quyet(): void
    {
        // The mirror of the test above, and the reason the veto reads only explicit
        // denials: "Khi nghỉ và vận động" names no sign and denies nothing, and it is
        // exactly the answer this tier exists to catch.
        $this->fakeRound(
            ['Anh/chị khó thở khi nghỉ hay chỉ khi vận động?'],
            ['cough', 'shortness_breath']
        );

        $phien = $this->answerClarify($this->runWizard(), ['Khi nghỉ và vận động']);

        $this->assertSame('emergency', $phien->outcome);
        $this->assertContains('breathing_difficulty', $this->flagCodes($phien));
    }

    public function test_cap_cuu_suy_ra_ghi_lai_nguon_goc(): void
    {
        $this->fakeRound(
            ['Anh/chị khó thở khi nghỉ hay chỉ khi vận động?'],
            ['cough', 'shortness_breath']
        );

        $phien = $this->answerClarify($this->runWizard(), ['Khi nghỉ và vận động']);

        $this->assertSame('emergency', $phien->outcome);
        // Which tier decided, recorded next to the codes the model proposed, so a later
        // review can tell a derived escalation from a text one.
        $this->assertContains('breathing_difficulty', (array) data_get($phien->ai_meta, 'derived_red_flags'));
    }

    public function test_cap_cuu_suy_ra_khong_hoi_them_vong(): void
    {
        $this->fakeRound(
            ['Anh/chị khó thở khi nghỉ hay chỉ khi vận động?'],
            ['cough', 'shortness_breath']
        );

        $phien = $this->answerClarify($this->runWizard(), ['Khi nghỉ và vận động']);

        $this->assertSame('completed', $phien->status);
        $this->assertSame([], $phien->pendingClarifyQuestions());
    }
}
