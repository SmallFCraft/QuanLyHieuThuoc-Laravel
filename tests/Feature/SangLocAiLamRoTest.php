<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use Database\Seeders\TriThucYTeSeeder;
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
class SangLocAiLamRoTest extends TestCase
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
            'ngay_sinh' => now()->subYears(34)->toDateString(),
        ]);

        SanPham::factory()->create(['ten' => 'Paracetamol 500mg',
            'thanh_phan' => 'Paracetamol 500mg',
            'cach_dung' => 'Giảm đau đầu, hạ sốt',
            'so_luong_ton' => 50,
            'ma_dm' => DanhMuc::first()->ma_dm,
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
    protected function runWizard(array $answers): PhienSangLoc
    {
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

    public function test_cac_cau_hoi_duoc_de_xuat_tren_trang_quy_trinh(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Anh/chị đau ở vùng nào của đầu?', 'Cơn đau có kèm buồn nôn không?'],
        ]);

        $phien = $this->runWizard($this->mildAdultAnswers());

        // Still in progress: the round is not a step, so nothing is finalised yet.
        $this->assertSame('in_progress', $phien->status);
        $this->assertNull($phien->outcome);
        $this->assertSame(1, $phien->ai_clarify_rounds);

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertSee(__('messages.health.clarify_title'))
            ->assertSee(__('messages.health.clarify_round', ['round' => 1, 'total' => 3]))
            ->assertSee('Anh/chị đau ở vùng nào của đầu?', false)
            ->assertSee(__('messages.health.clarify_skip'))
            // The wizard keeps its five steps: this is not step 6.
            ->assertDontSee('Bước 6/5');
    }

    public function test_tra_loi_hoan_tat_khi_mo_hinh_bao_da_du_thong_tin(): void
    {
        $this->fakeFactSequence([
            ['symptom_codes' => ['headache'], 'needs_more_info' => true, 'missing_questions' => ['Anh/chị đã dùng thuốc gì chưa?']],
            // Second call: enough information, so the conversation ends.
            ['symptom_codes' => ['headache'], 'needs_more_info' => false, 'missing_questions' => []],
        ]);

        $phien = $this->answerClarify(
            $this->runWizard($this->mildAdultAnswers()),
            ['đã uống thuốc mà không đỡ']
        );

        $this->assertNotSame('in_progress', $phien->status);
        // The answer text reached collectedText(), so the caution red flag fired and
        // downgraded self_care. The queue is opt-in, so the session is still completed.
        $this->assertSame('pharmacist_review', $phien->outcome);
        $this->assertSame('completed', $phien->status);
        $this->assertSame(
            'đã uống thuốc mà không đỡ',
            $phien->collected_data['ai_clarify_answers'][0]['answer']
        );
        $this->assertSame(1, $phien->ai_clarify_rounds);
    }

    public function test_mo_hinh_co_the_hoi_vong_hai_sau_khi_doc_cau_tra_loi_dau(): void
    {
        $this->fakeFactSequence([
            ['symptom_codes' => ['headache'], 'needs_more_info' => true, 'missing_questions' => ['Anh/chị đau ở vùng nào của đầu?']],
            ['symptom_codes' => ['headache'], 'needs_more_info' => true, 'missing_questions' => ['Cơn đau có kèm buồn nôn hay sợ ánh sáng không?']],
            ['symptom_codes' => ['headache'], 'needs_more_info' => false, 'missing_questions' => []],
        ]);

        $phien = $this->answerClarify($this->runWizard($this->mildAdultAnswers()), ['đau nửa đầu bên phải']);

        // Round 2 is open, not finalised.
        $this->assertSame('in_progress', $phien->status);
        $this->assertSame(2, $phien->ai_clarify_rounds);
        $this->assertSame(
            ['Cơn đau có kèm buồn nôn hay sợ ánh sáng không?'],
            $phien->pendingClarifyQuestions()
        );

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertSee(__('messages.health.clarify_round', ['round' => 2, 'total' => 3]))
            ->assertSee('Cơn đau có kèm buồn nôn hay sợ ánh sáng không?', false);

        $phien = $this->answerClarify($phien, ['không buồn nôn']);

        $this->assertSame('completed', $phien->status);
        $this->assertCount(2, $phien->collected_data['ai_clarify_answers']);
    }

    /**
     * Measured on a live session: the model re-asked "sốt" in rounds 1, 2 and 3 and
     * "đờm hay khan" in rounds 1 and 3, even though the customer had already answered
     * "không". Three rounds spent re-asking what was answered is what made the
     * consultation feel like a broken form.
     *
     * A compound question answered with a single "không" denies every symptom it
     * names, so a later question naming only those symptoms has nothing left to ask.
     * A question naming a symptom never asked about survives — "đau ngực" was new in
     * round 2 above and was kept.
     */
    public function test_trieu_chung_khach_da_phu_dinh_khong_bi_hoi_lai(): void
    {
        $this->fakeFactSequence([
            [
                'symptom_codes' => ['cough'],
                'needs_more_info' => true,
                'missing_questions' => ['Anh/chị có sốt hoặc khó thở không ạ?', 'Cơn ho đã mấy ngày rồi ạ?'],
            ],
            [
                'symptom_codes' => ['cough'],
                'needs_more_info' => true,
                'missing_questions' => [
                    'Hiện tại anh/chị có bị sốt không ạ?',
                    'Anh/chị có thấy khó thở hoặc đau ngực không ạ?',
                    'Anh/chị đã uống thuốc gì cho cơn ho chưa ạ?',
                ],
            ],
            ['symptom_codes' => ['cough'], 'needs_more_info' => false, 'missing_questions' => []],
        ]);

        $phien = $this->runWizard([
            1 => 'ho',
            2 => 'hôm nay',
            3 => 'chưa uống gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $this->assertSame(
            ['Anh/chị có sốt hoặc khó thở không ạ?', 'Cơn ho đã mấy ngày rồi ạ?'],
            $phien->pendingClarifyQuestions()
        );

        $phien = $this->answerClarify($phien, ['không', 'mấy hôm nay rồi']);

        // "sốt" was denied outright. "đau ngực" was never asked, so that half of the
        // compound question is new information and must survive.
        $this->assertSame(
            [
                'Anh/chị có thấy khó thở hoặc đau ngực không ạ?',
                'Anh/chị đã uống thuốc gì cho cơn ho chưa ạ?',
            ],
            $phien->pendingClarifyQuestions()
        );
    }

    /**
     * Session 52, round 3 asked "Anh/chị bao nhiêu tuổi ạ?" although the account
     * birth_date already answered it. The customer's own round-1 answer was
     * "còn sốt.", which folds to "con" and trips CHILD_MARKERS, so knownFacts()
     * read the complaint as a child's and dropped the age topic entirely.
     */
    public function test_tuoi_tai_khoan_khong_bi_hoi_lai_khi_cau_tra_loi_chua_tu_con(): void
    {
        $this->fakeFactSequence([
            ['symptom_codes' => ['fever'], 'needs_more_info' => true, 'missing_questions' => ['Hiện tại anh/chị còn sốt không ạ?']],
            [
                'symptom_codes' => ['fever'],
                'needs_more_info' => true,
                'missing_questions' => ['Anh/chị bao nhiêu tuổi ạ?', 'Cơn tiêu chảy đã mấy ngày rồi ạ?'],
            ],
            ['symptom_codes' => ['fever'], 'needs_more_info' => false, 'missing_questions' => []],
        ]);

        $phien = $this->runWizard([
            1 => 'tiêu chảy',
            2 => 'hôm nay',
            3 => 'chưa uống gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $this->assertSame(['Hiện tại anh/chị còn sốt không ạ?'], $phien->pendingClarifyQuestions());

        $phien = $this->answerClarify($phien, ['còn sốt.']);

        // The age question is already answered by the profile; the fever follow-up
        // is not.
        $this->assertSame(['Cơn tiêu chảy đã mấy ngày rồi ạ?'], $phien->pendingClarifyQuestions());
    }

    /**
     * Session 52 asked what medication the customer had taken in all three rounds —
     * "đã uống thuốc gì", "đã dùng thuốc gì, uống bao lâu rồi", "uống thuốc gì
     * không" — against three answers that all said they had taken nothing.
     *
     * The symptom vocabulary cannot catch this: "thuốc" is not a symptom, so
     * deniedSymptomTerms() has nothing to match.
     */
    public function test_cau_hoi_thuoc_khong_bi_hoi_lai_sau_khi_phu_dinh(): void
    {
        $this->fakeFactSequence([
            ['symptom_codes' => ['headache'], 'needs_more_info' => true, 'missing_questions' => ['Anh/chị đã uống thuốc gì cho chứng này chưa ạ?']],
            [
                'symptom_codes' => ['headache'],
                'needs_more_info' => true,
                'missing_questions' => ['Anh/chị đã dùng thuốc gì, uống bao lâu rồi ạ?', 'Cơn đau đầu ở vị trí nào ạ?'],
            ],
            ['symptom_codes' => ['headache'], 'needs_more_info' => false, 'missing_questions' => []],
        ]);

        $phien = $this->runWizard([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'Đã dùng và có đỡ',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $this->assertSame(['Anh/chị đã uống thuốc gì cho chứng này chưa ạ?'], $phien->pendingClarifyQuestions());

        $phien = $this->answerClarify($phien, ['chưa uống ạ.']);

        // Only the location question survives: the medication one was answered.
        $this->assertSame(['Cơn đau đầu ở vị trí nào ạ?'], $phien->pendingClarifyQuestions());
    }

    /**
     * Session 52 round 1 asked "Hiện tại còn sốt hoặc đi ngoài ra máu không ạ?" and
     * the customer answered "còn sốt." — affirming fever only. Round 2 asked "Có kèm
     * nôn hoặc đi ngoài ra máu không ạ?", repeating "đi ngoài ra máu" which the
     * customer had just left unchosen, and the customer answered "không".
     *
     * Also covers the standalone "nôn" vocabulary gap: the symptom dictionary holds
     * "buồn nôn" / "nôn ói" / "nôn ra máu", not bare "nôn", so after "Có kèm nôn
     * không ạ?" is denied, a later question asking "Có nôn không ạ?" would slip
     * past unless bare "nôn" is recognised.
     */
    public function test_cau_hoi_non_don_thuan_da_bi_phu_dinh_khong_bi_hoi_lai(): void
    {
        $this->fakeFactSequence([
            ['symptom_codes' => ['diarrhea'], 'needs_more_info' => true, 'missing_questions' => ['Có kèm nôn không ạ?']],
            [
                'symptom_codes' => ['diarrhea'],
                'needs_more_info' => true,
                'missing_questions' => ['Hiện tại anh/chị có nôn không ạ?', 'Anh/chị đi ngoài mấy lần một ngày ạ?'],
            ],
            ['symptom_codes' => ['diarrhea'], 'needs_more_info' => false, 'missing_questions' => []],
        ]);

        $phien = $this->runWizard([
            1 => 'tiêu chảy',
            2 => 'hôm nay',
            3 => 'chưa uống gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $this->assertSame(['Có kèm nôn không ạ?'], $phien->pendingClarifyQuestions());

        $phien = $this->answerClarify($phien, ['không']);

        $this->assertSame(['Anh/chị đi ngoài mấy lần một ngày ạ?'], $phien->pendingClarifyQuestions());
    }

    public function test_loi_nhac_vong_hai_mang_theo_cau_tra_loi_vong_dau(): void
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

    public function test_cuoc_hoi_thoai_dung_o_gioi_han_so_vong(): void
    {
        // A model that never says it is satisfied must still be stopped.
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Còn dấu hiệu nào khác không ạ?'],
        ]);

        $phien = $this->runWizard($this->mildAdultAnswers());
        $this->assertSame(1, $phien->ai_clarify_rounds);

        $phien = $this->answerClarify($phien, ['không có gì thêm']);
        $this->assertSame(2, $phien->ai_clarify_rounds);
        $this->assertSame('in_progress', $phien->status);

        $phien = $this->answerClarify($phien, ['vẫn không có']);
        $this->assertSame(3, $phien->ai_clarify_rounds);
        $this->assertSame('in_progress', $phien->status);

        $phien = $this->answerClarify($phien, ['thật sự không có']);

        // No fourth round: PhienSangLoc::MAX_CLARIFY_ROUNDS is a hard stop.
        $this->assertSame('completed', $phien->status);
        $this->assertSame(PhienSangLoc::MAX_CLARIFY_ROUNDS, $phien->ai_clarify_rounds);
        $this->assertCount(3, $phien->collected_data['ai_clarify_answers']);
        $this->assertSame([], $phien->pendingClarifyQuestions());
    }

    public function test_bo_qua_ket_thuc_hoi_thoai_vinh_vien(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Anh/chị đau ở vùng nào?'],
        ]);

        $phien = $this->runWizard($this->mildAdultAnswers());

        $this->actingAs($this->nguoiDung)->post(route('sang-loc.lam-ro'), [
            'triage_session_id' => $phien->ma_sl,
            'skipped' => 1,
        ]);

        $phien = $phien->fresh();

        $this->assertSame('self_care', $phien->outcome);
        $this->assertArrayNotHasKey('ai_clarify_answers', $phien->collected_data);
        // Declining once means no more rounds, even though the model kept asking.
        $this->assertSame([], $phien->pendingClarifyQuestions());
    }

    public function test_phien_da_xong_khong_bi_mo_lai_boi_bai_gui_lam_ro_phat_lai(): void
    {
        $this->fakeFactSequence([
            ['symptom_codes' => ['headache'], 'needs_more_info' => true, 'missing_questions' => ['Anh/chị đau ở vùng nào?']],
            ['symptom_codes' => ['headache'], 'needs_more_info' => false, 'missing_questions' => []],
        ]);

        $phien = $this->answerClarify($this->runWizard($this->mildAdultAnswers()), ['đau nửa đầu']);

        $this->assertNotSame('in_progress', $phien->status);

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.lam-ro'), [
                'triage_session_id' => $phien->ma_sl,
                'clarify' => ['thêm nữa'],
            ])
            ->assertRedirect(route('sang-loc.kiem-tra'));

        $this->assertCount(1, $phien->fresh()->collected_data['ai_clarify_answers']);
    }

    public function test_cap_cuu_khong_bao_gio_bi_hoi_them_chi_tiet(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['chest_pain'],
            'needs_more_info' => true,
            'missing_questions' => ['Cơn đau kéo dài bao lâu?'],
        ]);

        $phien = $this->runWizard([1 => 'tôi bị đau ngực dữ dội']);

        $this->assertSame('emergency', $phien->outcome);
        $this->assertSame(0, $phien->ai_clarify_rounds);
        Http::assertNothingSent();
    }

    public function test_cau_tra_loi_cap_cuu_giua_cuoc_hoi_thoai_ket_thuc_ngay(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Anh/chị còn dấu hiệu nào khác không?'],
        ]);

        $phien = $this->answerClarify(
            $this->runWizard($this->mildAdultAnswers()),
            ['tôi bị co giật lúc chiều']
        );

        // The red flag in the answer outranks the model's wish to keep asking.
        $this->assertSame('emergency', $phien->outcome);
        $this->assertSame('completed', $phien->status);
        $this->assertSame(1, $phien->ai_clarify_rounds);
        $this->assertSame([], $phien->suggested_products);
    }

    public function test_tat_co_hieu_bo_qua_toan_bo_vong(): void
    {
        config()->set('chatbot.ai.triage.clarify_round', false);

        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Anh/chị đau ở vùng nào?'],
        ]);

        $phien = $this->runWizard($this->mildAdultAnswers());

        $this->assertSame('self_care', $phien->outcome);
        $this->assertSame(0, $phien->ai_clarify_rounds);
    }

    public function test_khong_co_cau_hoi_thi_khong_mo_vong(): void
    {
        $this->fakeFacts(['symptom_codes' => ['headache'], 'missing_questions' => []]);

        $phien = $this->runWizard($this->mildAdultAnswers());

        $this->assertSame('self_care', $phien->outcome);
        $this->assertSame(0, $phien->ai_clarify_rounds);
    }

    public function test_khong_can_them_thong_tin_dung_vong_du_co_danh_sach_cau_hoi(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => false,
            'missing_questions' => ['Anh/chị đau ở vùng nào?'],
        ]);

        $phien = $this->runWizard($this->mildAdultAnswers());

        // The model's own stop signal wins: no round is offered.
        $this->assertSame('self_care', $phien->outcome);
        $this->assertSame(0, $phien->ai_clarify_rounds);
    }

    public function test_cau_hoi_chua_lien_ket_bi_loai(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Bấm vào https://evil.test để xác nhận', 'Anh/chị đau ở vùng nào?'],
        ]);

        $phien = $this->runWizard($this->mildAdultAnswers());

        $this->assertSame(['Anh/chị đau ở vùng nào?'], $phien->pendingClarifyQuestions());
    }

    public function test_moi_vong_chi_giu_toi_da_ba_cau_hoi(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Câu một?', 'Câu hai?', 'Câu ba?', 'Câu bốn?'],
        ]);

        $phien = $this->runWizard($this->mildAdultAnswers());

        $this->assertCount(
            PhienSangLoc::MAX_CLARIFY_QUESTIONS_PER_ROUND,
            $phien->pendingClarifyQuestions()
        );
    }

    public function test_cau_tra_loi_thu_tu_trong_mot_vong_bi_tu_choi(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Câu một?', 'Câu hai?', 'Câu ba?'],
        ]);

        $phien = $this->runWizard($this->mildAdultAnswers());

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.lam-ro'), [
                'triage_session_id' => $phien->ma_sl,
                'clarify' => ['một', 'hai', 'ba', 'bốn'],
            ])
            ->assertSessionHasErrors('clarify');

        $this->assertSame('in_progress', $phien->fresh()->status);
    }

    public function test_khong_the_gui_lam_ro_khi_khong_co_vong_dang_mo(): void
    {
        $this->fakeFacts(['symptom_codes' => ['headache'], 'missing_questions' => []]);

        $this->actingAs($this->nguoiDung)->get(route('sang-loc.kiem-tra'));
        $phien = PhienSangLoc::where('ma_nd', $this->nguoiDung->ma_nd)->inProgress()->latest('ma_sl')->firstOrFail();

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.lam-ro'), [
                'triage_session_id' => $phien->ma_sl,
                'clarify' => ['gì đó'],
            ])
            ->assertRedirect(route('sang-loc.kiem-tra'))
            ->assertSessionHas('error');

        $this->assertSame('in_progress', $phien->fresh()->status);
    }

    public function test_nguoi_dung_khac_khong_the_tra_loi_vong_cua_nguoi_khac(): void
    {
        $this->fakeFacts([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Anh/chị đau ở vùng nào?'],
        ]);

        $phien = $this->runWizard($this->mildAdultAnswers());
        $other = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);

        $this->actingAs($other)->post(route('sang-loc.lam-ro'), [
            'triage_session_id' => $phien->ma_sl,
            'clarify' => ['tôi chen ngang'],
        ]);

        $phien = $phien->fresh();
        $this->assertSame('in_progress', $phien->status);
        $this->assertArrayNotHasKey('ai_clarify_answers', $phien->collected_data);
    }

    public function test_khach_chua_dang_nhap_khong_the_truy_cap_tuyen_lam_ro(): void
    {
        $this->post(route('sang-loc.lam-ro'), ['triage_session_id' => 1])
            ->assertRedirect(route('login'));
    }
}
