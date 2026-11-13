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
class TraLoiPhuDinhSangLocTest extends TestCase
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

    protected function runWizard(): PhienSangLoc
    {
        $this->actingAs($this->nguoiDung)->get(route('sang-loc.kiem-tra'));
        $phien = PhienSangLoc::where('ma_nd', $this->nguoiDung->ma_nd)->inProgress()->latest('ma_sl')->firstOrFail();

        foreach ([1 => 'tôi bị đau đầu', 2 => 'hôm nay', 3 => 'chưa dùng gì', 4 => 'không có', 5 => 'không có thêm'] as $step => $answer) {
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

    public function test_phu_dinh_moi_dau_hieu_nguy_hiem_khong_kich_hoat_cap_cuu(): void
    {
        // The exact question the model asks, and the exact answer a healthy patient
        // gives. Every red-flag keyword is present, all negated.
        $this->fakeOneRound('Anh/chị có kèm sốt, nôn hay cứng cổ không ạ?');

        $phien = $this->runWizard();
        $phien = $this->answerClarify($phien, ['không sốt, không nôn, không cứng cổ']);

        $this->assertSame('self_care', $phien->outcome);
        $this->assertSame([], $phien->triggered_red_flags);
        $this->assertNotEmpty($phien->suggested_products);
    }

    public function test_phu_dinh_dau_hieu_than_kinh_khong_kich_hoat_di_kham(): void
    {
        $this->fakeOneRound('Anh/chị có mờ mắt, yếu tay chân hay nói khó không ạ?');

        $phien = $this->runWizard();
        $phien = $this->answerClarify($phien, ['không, mắt bình thường, tay chân bình thường']);

        $this->assertSame('self_care', $phien->outcome);
    }

    public function test_dau_hieu_nguy_hiem_that_trong_cau_tra_loi_theo_sau_van_lam_tang_muc(): void
    {
        $this->fakeOneRound('Anh/chị có kèm dấu hiệu nào khác không ạ?');

        $phien = $this->runWizard();
        $phien = $this->answerClarify($phien, ['tôi bị co giật lúc chiều']);

        $this->assertSame('emergency', $phien->outcome);
        $this->assertSame([], $phien->suggested_products);
    }

    public function test_cau_tra_loi_hon_hop_giu_dau_hieu_duoc_khang_dinh(): void
    {
        $this->fakeOneRound('Anh/chị có kèm sốt, nôn hay cứng cổ không ạ?');

        $phien = $this->runWizard();
        // Denies two, reports one. The reported one must win: a negation only reaches
        // the phrase directly after it, never the rest of the sentence.
        $phien = $this->answerClarify($phien, ['không sốt, không nôn, nhưng bị cứng cổ']);

        $this->assertNotSame('self_care', $phien->outcome);
    }

    public function test_phu_dinh_cach_xa_trieu_chung_khong_duoc_xem_la_phu_dinh_trieu_chung(): void
    {
        $this->fakeOneRound('Cơn đau thế nào ạ?');

        $phien = $this->runWizard();
        // "không" belongs to "không chịu được", not to the chest pain.
        $phien = $this->answerClarify($phien, ['tôi không chịu được, còn bị đau ngực dữ dội nữa']);

        $this->assertSame('emergency', $phien->outcome);
    }

    public function test_noi_chua_thu_thuoc_khong_phai_co_than_trong(): void
    {
        $this->fakeOneRound('Anh/chị đã dùng thuốc gì chưa ạ?');

        $phien = $this->runWizard();
        $phien = $this->answerClarify($phien, ['chưa uống thuốc gì cả']);

        // "đã dùng thuốc mà không đỡ" is a caution flag; "chưa dùng" is not.
        $this->assertSame('self_care', $phien->outcome);
    }

    public function test_thuoc_khong_co_tac_dung_van_la_co_than_trong(): void
    {
        $this->fakeOneRound('Anh/chị đã dùng thuốc gì chưa ạ?');

        $phien = $this->runWizard();
        $phien = $this->answerClarify($phien, ['uống paracetamol mà không đỡ']);

        // The keyword is itself built around "không", so the negation check must not
        // cancel it.
        $this->assertSame('pharmacist_review', $phien->outcome);
    }
}
