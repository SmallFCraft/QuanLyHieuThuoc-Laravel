<?php

namespace Tests\Feature;

use App\Models\NhatKyKiemToan;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use App\Services\Triage\Ai\SangLocAiVietLaiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI rewrite of the pharmacist-written self-care advice.
 *
 * The invariants under test: `advice_given` is never overwritten, only `self_care`
 * is eligible, and a rewrite that drops a numeric threshold is discarded.
 */
class SangLocAiVietLaiLoiKhuyenTest extends TestCase
{
    use RefreshDatabase;

    protected const SOURCE_ADVICE = "Nghỉ ngơi, uống nhiều nước, mặc thoáng.\nĐo lại nhiệt độ mỗi 4–6 giờ.";
    protected const SOURCE_SAFETY = 'Đi khám ngay nếu sốt trên 39 độ không hạ hoặc sốt quá 3 ngày.';

    protected NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config()->set('chatbot.ai.triage.advice_rewrite', true);

        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
    }

    protected function fakeProviderReply(string $content): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
        ])]);
    }

    protected function selfCareSession(array $attributes = []): PhienSangLoc
    {
        return PhienSangLoc::create(array_merge([
            'ma_nd' => $this->nguoiDung->ma_nd,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'self_care',
            'advice_given' => self::SOURCE_ADVICE,
            'safety_net' => self::SOURCE_SAFETY,
            'collected_data' => ['raw_symptom_text' => 'toi bi sot nhe'],
        ], $attributes));
    }

    protected function vietLai(PhienSangLoc $phien): PhienSangLoc
    {
        app(SangLocAiVietLaiService::class)->vietLai($phien);

        return $phien->refresh();
    }

    public function test_tat_co_hieu_khong_gui_yeu_cau(): void
    {
        config()->set('chatbot.ai.triage.advice_rewrite', false);

        $phien = $this->vietLai($this->selfCareSession());

        Http::assertNothingSent();
        $this->assertNull($phien->ai_advice);
        $this->assertNull($phien->ai_status);
    }

    public function test_ban_viet_lai_tot_duoc_luu_canh_ban_goc(): void
    {
        $this->fakeProviderReply(implode("\n", [
            'LỜI KHUYÊN:',
            'Anh/chị nghỉ ngơi, uống nhiều nước và mặc thoáng nhé. Nhớ đo lại nhiệt độ mỗi 4–6 giờ ạ.',
            '',
            'NGƯỠNG CẦN ĐI KHÁM:',
            'Anh/chị đi khám ngay nếu sốt trên 39 độ không hạ, hoặc sốt quá 3 ngày ạ.',
        ]));

        $phien = $this->vietLai($this->selfCareSession());

        $this->assertSame('ok', $phien->ai_status);
        $this->assertStringContainsString('nghỉ ngơi, uống nhiều nước', $phien->ai_advice);
        $this->assertStringContainsString('39 độ', $phien->ai_safety_net);

        // The deterministic record is untouched.
        $this->assertSame(self::SOURCE_ADVICE, $phien->advice_given);
        $this->assertSame(self::SOURCE_SAFETY, $phien->safety_net);
    }

    public function test_loi_mo_dau_nhac_lai_bi_loai_khoi_loi_khuyen(): void
    {
        // Observed against the live provider: the model repeated the prompt's
        // "Kết luận hệ thống:" line before the label, and it rendered verbatim on the
        // result page above the actual advice.
        $this->fakeProviderReply(implode("\n", [
            'Kết luận hệ thống: có thể tự chăm sóc tại nhà.',
            '',
            'LỜI KHUYÊN:',
            'Anh/chị nghỉ ngơi, uống nhiều nước và mặc thoáng nhé. Nhớ đo lại nhiệt độ mỗi 4–6 giờ ạ.',
            '',
            'NGƯỠNG CẦN ĐI KHÁM:',
            'Anh/chị đi khám ngay nếu sốt trên 39 độ không hạ, hoặc sốt quá 3 ngày ạ.',
        ]));

        $phien = $this->vietLai($this->selfCareSession());

        $this->assertSame('ok', $phien->ai_status);
        $this->assertStringNotContainsString('Kết luận hệ thống', $phien->ai_advice);
        $this->assertStringStartsWith('Anh/chị nghỉ ngơi', $phien->ai_advice);
    }

    public function test_ban_viet_lai_lam_mat_nguong_bi_tu_choi(): void
    {
        // "39" and "3" are gone: the safety net has silently lost its trigger.
        $this->fakeProviderReply(implode("\n", [
            'LỜI KHUYÊN:',
            'Anh/chị nghỉ ngơi, uống nhiều nước và mặc thoáng nhé. Đo lại nhiệt độ mỗi 4–6 giờ ạ.',
            '',
            'NGƯỠNG CẦN ĐI KHÁM:',
            'Anh/chị đi khám ngay nếu sốt cao không hạ hoặc sốt kéo dài ạ.',
        ]));

        $phien = $this->vietLai($this->selfCareSession());

        $this->assertSame('rejected', $phien->ai_status);
        $this->assertNull($phien->ai_advice);
        $this->assertSame(self::SOURCE_ADVICE, $phien->advice_given);
    }

    public function test_phan_hoi_json_bi_tu_choi(): void
    {
        $this->fakeProviderReply('{"outcome":"self_care","advice":"uống nước"}');

        $phien = $this->vietLai($this->selfCareSession());

        $this->assertSame('rejected', $phien->ai_status);
        $this->assertNull($phien->ai_advice);
    }

    public function test_phan_hoi_markdown_bi_tu_choi(): void
    {
        $this->fakeProviderReply(implode("\n", [
            '## LỜI KHUYÊN',
            '**Anh/chị** nghỉ ngơi, uống nhiều nước, mặc thoáng. Đo nhiệt độ mỗi 4–6 giờ.',
            '',
            'NGƯỠNG CẦN ĐI KHÁM:',
            'Đi khám nếu sốt trên 39 độ hoặc quá 3 ngày.',
        ]));

        $phien = $this->vietLai($this->selfCareSession());

        $this->assertSame('rejected', $phien->ai_status);
    }

    public function test_ket_qua_cap_cuu_khong_bao_gio_duoc_viet_lai(): void
    {
        $phien = $this->vietLai($this->selfCareSession([
            'outcome' => 'emergency',
            'advice_given' => 'Anh/chị hãy gọi ngay 115 hoặc đến cơ sở y tế gần nhất.',
            'safety_net' => null,
        ]));

        Http::assertNothingSent();
        $this->assertNull($phien->ai_advice);
        $this->assertStringContainsString('115', $phien->advice_given);
    }

    public function test_ket_qua_di_kham_khong_bao_gio_duoc_viet_lai(): void
    {
        $phien = $this->vietLai($this->selfCareSession([
            'outcome' => 'refer_doctor',
            'safety_net' => null,
        ]));

        Http::assertNothingSent();
        $this->assertNull($phien->ai_advice);
    }

    public function test_ket_qua_duoc_si_duyet_duoc_viet_lai_vi_cau_tra_loi_ai_la_chinh(): void
    {
        // The customer now reads the AI answer first and asks for a human only if
        // they want one, so this text is what they actually see.
        $this->fakeProviderReply(implode("\n", [
            'LỜI KHUYÊN:',
            'Anh/chị nghỉ ngơi, uống nhiều nước, mặc thoáng và đo nhiệt độ mỗi 4–6 giờ nhé.',
        ]));

        $phien = $this->vietLai($this->selfCareSession([
            'outcome' => 'pharmacist_review',
            'safety_net' => null,
        ]));

        $this->assertSame('ok', $phien->ai_status);
        $this->assertStringContainsString('nghỉ ngơi', $phien->ai_advice);
        $this->assertSame(self::SOURCE_ADVICE, $phien->advice_given);
    }

    public function test_ca_dang_cho_van_hien_cau_tra_loi_cua_con_nguoi_khi_co(): void
    {
        // The AI rewrite and the pharmacist note coexist: the note is rendered above
        // and is never replaced by machine prose.
        $this->fakeProviderReply(implode("\n", [
            'LỜI KHUYÊN:',
            'Anh/chị nghỉ ngơi, uống nhiều nước, mặc thoáng, đo nhiệt độ mỗi 4–6 giờ.',
        ]));

        $phien = $this->vietLai($this->selfCareSession([
            'outcome' => 'pharmacist_review',
            'status' => 'reviewed',
            'safety_net' => null,
            'pharmacist_note' => 'Dạ, em là dược sĩ Hạnh, anh/chị theo dõi thêm 2 ngày nhé.',
            'reviewed_at' => now(),
        ]));

        // The pharmacist's note is what the customer acts on. The machine rewrite is
        // no longer printed beside it — two versions of one instruction on one page
        // read as two different instructions.
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->assertSee('dược sĩ Hạnh', false)
            ->assertDontSee('đo nhiệt độ mỗi 4–6 giờ nhé', false);
    }

    public function test_loi_nha_cung_cap_duoc_ghi_nhan_va_trang_khong_thay_doi(): void
    {
        Http::fake(['*' => Http::response('gateway down', 502)]);

        $phien = $this->vietLai($this->selfCareSession());

        $this->assertSame('failed', $phien->ai_status);
        $this->assertNull($phien->ai_advice);
        $this->assertSame(self::SOURCE_ADVICE, $phien->advice_given);
    }

    public function test_loi_nhac_khong_chua_van_ban_tu_do_hay_du_lieu_ho_so(): void
    {
        \App\Models\HoSoSucKhoe::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'allergies' => ['penicillin'],
            'consented_at' => now(),
        ]);

        $this->fakeProviderReply(implode("\n", [
            'LỜI KHUYÊN:',
            'Anh/chị nghỉ ngơi, uống nước, mặc thoáng, đo nhiệt độ mỗi 4–6 giờ.',
            '',
            'NGƯỠNG CẦN ĐI KHÁM:',
            'Đi khám nếu sốt trên 39 độ hoặc quá 3 ngày.',
        ]));

        $this->vietLai($this->selfCareSession());

        Http::assertSent(function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString('toi bi sot nhe', $body);
            $this->assertStringNotContainsString('penicillin', $body);

            return true;
        });
    }

    /**
     * The rewrite still lands in the database — the audit trail and the pharmacist
     * queue read it — but the result page shows only the pharmacist-written advice.
     * Rendering both put two versions of one instruction on one page.
     */
    public function test_trang_ket_qua_hien_loi_khuyen_duoc_si_thay_vi_ban_viet_lai(): void
    {
        $this->fakeProviderReply(implode("\n", [
            'LỜI KHUYÊN:',
            'Anh/chị nghỉ ngơi, uống nhiều nước, mặc thoáng và đo nhiệt độ mỗi 4–6 giờ nhé.',
            '',
            'NGƯỠNG CẦN ĐI KHÁM:',
            'Đi khám ngay nếu sốt trên 39 độ hoặc quá 3 ngày ạ.',
        ]));

        $phien = $this->vietLai($this->selfCareSession());

        $this->assertSame('ok', $phien->ai_status);
        $this->assertStringContainsString('đo nhiệt độ', (string) $phien->ai_advice);

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->assertSee('Đo lại nhiệt độ mỗi 4–6 giờ.', false)
            ->assertDontSee('đo nhiệt độ mỗi 4–6 giờ nhé', false);
    }

    public function test_dong_nhat_ky_ghi_trang_thai_nhung_khong_ghi_van_ban_loi_khuyen(): void
    {
        $this->fakeProviderReply(implode("\n", [
            'LỜI KHUYÊN:',
            'Anh/chị nghỉ ngơi, uống nước, mặc thoáng, đo nhiệt độ mỗi 4–6 giờ.',
            '',
            'NGƯỠNG CẦN ĐI KHÁM:',
            'Đi khám nếu sốt trên 39 độ hoặc quá 3 ngày.',
        ]));

        $this->vietLai($this->selfCareSession());

        $log = NhatKyKiemToan::where('action', 'triage_ai_advice')->firstOrFail();
        $encoded = json_encode($log->new_values, JSON_UNESCAPED_UNICODE);

        $this->assertSame('ok', $log->new_values['ai_status']);
        $this->assertStringNotContainsString('nghỉ ngơi', $encoded);
        $this->assertStringNotContainsString('toi bi sot nhe', $encoded);
    }
}
