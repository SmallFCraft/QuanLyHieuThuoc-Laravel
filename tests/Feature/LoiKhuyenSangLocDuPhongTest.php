<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The advice rewrite has no worker on a dev box, so the same stranded-job fallback
 * the briefing got must exist here too.
 *
 * Without it, teaching the result page to reload on `stranded` would reload forever:
 * the advice job is never retired and `ai_status` never leaves null, so every reload
 * would re-render the pending banner. The two changes ship together or neither does.
 */
class LoiKhuyenSangLocDuPhongTest extends TestCase
{
    use RefreshDatabase;

    protected const SOURCE_ADVICE = "Nghỉ ngơi, uống nhiều nước.\nĐo lại nhiệt độ mỗi 4–6 giờ.";
    protected const SOURCE_SAFETY = 'Đi khám ngay nếu sốt trên 39 độ không hạ hoặc sốt quá 3 ngày.';

    protected NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config()->set('chatbot.ai.triage.advice_rewrite', true);

        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
    }

    protected function fakeRewrite(): void
    {
        Http::fake(['*/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => implode("\n", [
                'LỜI KHUYÊN:',
                'Anh/chị nghỉ ngơi, uống nhiều nước và đo lại nhiệt độ mỗi 4–6 giờ nhé.',
                '',
                'NGƯỠNG CẦN ĐI KHÁM:',
                'Anh/chị đi khám ngay nếu sốt trên 39 độ không hạ, hoặc sốt quá 3 ngày ạ.',
            ])]]],
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

    protected function strandedAdvice(PhienSangLoc $phien, int $ageSeconds = AiJob::SYNC_FALLBACK_SECONDS + 1): AiJob
    {
        $job = AiJob::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'ma_nd' => $phien->ma_nd,
            'kind' => AiJob::KIND_TRIAGE_ADVICE,
            'status' => 'queued',
            'payload' => ['ma_sl' => $phien->ma_sl],
        ]);

        $job->forceFill(['created_at' => now()->subSeconds($ageSeconds)])->save();

        return $job->refresh();
    }

    public function test_cong_viec_loi_khuyen_mac_ket_duoc_ket_thuc_ma_khong_chan_trang(): void
    {
        Http::preventStrayRequests();

        $phien = $this->selfCareSession();
        $job = $this->strandedAdvice($phien);

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk();

        // No provider call: the rewrite never runs inside the request.
        Http::assertNothingSent();

        $phien->refresh();
        $this->assertNull($phien->ai_status);
        $this->assertNull($phien->ai_advice);

        // The job is retired rather than polled forever for a worker that never comes.
        $this->assertSame('failed', $job->refresh()->status);
        $this->assertStringContainsString('no worker picked', $job->error);

        // The pharmacist's authoritative text still renders.
        $this->assertSame(self::SOURCE_ADVICE, $phien->advice_given);
    }

    /**
     * A job a worker may still pick up must stay a poll target: running it inline
     * would send the same rewrite request twice.
     */
    public function test_cong_viec_loi_khuyen_vua_xep_hang_duoc_de_cho_bo_xu_ly(): void
    {
        Http::fake();

        $phien = $this->selfCareSession();
        $job = $this->strandedAdvice($phien, 0);

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk();

        Http::assertNothingSent();
        $this->assertSame('queued', $job->refresh()->status);
        $this->assertNull($phien->refresh()->ai_status);
    }

    /**
     * The key regression: once retired for being stranded, a second page load
     * does not retry or block. The advice job stays failed and the page loads immediately.
     */
    public function test_lan_tai_thu_hai_sau_du_phong_khong_chay_lai(): void
    {
        Http::preventStrayRequests();

        $phien = $this->selfCareSession();
        $this->strandedAdvice($phien);

        $this->actingAs($this->nguoiDung)->get(route('sang-loc.ket-qua', $phien->ma_sl))->assertOk();

        $phien->refresh();
        $this->assertNull($phien->ai_status);

        $second = $this->actingAs($this->nguoiDung)->get(route('sang-loc.ket-qua', $phien->ma_sl));

        $second->assertOk();
        Http::assertNothingSent();

        // No pending job id reaches the page, so the poller never starts.
        $this->assertNull($second->viewData('aiJobId'));
    }

    public function test_cong_viec_loi_khuyen_tung_that_bai_thu_lai_mot_lan_va_gioi_han_hai_lan(): void
    {
        $phien = $this->selfCareSession(['ai_status' => 'failed']);

        AiJob::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'ma_nd' => $phien->ma_nd,
            'kind' => AiJob::KIND_TRIAGE_ADVICE,
            'status' => 'failed',
            'error' => 'timeout',
            'payload' => ['ma_sl' => $phien->ma_sl],
        ]);

        $this->fakeRewrite();

        // Second load triggers the single retry.
        $this->actingAs($this->nguoiDung)->get(route('sang-loc.ket-qua', $phien->ma_sl))->assertOk();

        $this->assertSame('ok', $phien->refresh()->ai_status);
        $this->assertSame(2, AiJob::where('kind', AiJob::KIND_TRIAGE_ADVICE)->count());

        // Subsequent loads never retry further even if forced back to failed.
        $phien->forceFill(['ai_status' => 'failed'])->save();
        Http::fake();
        $this->actingAs($this->nguoiDung)->get(route('sang-loc.ket-qua', $phien->ma_sl))->assertOk();
        Http::assertNothingSent();
        $this->assertSame(2, AiJob::where('kind', AiJob::KIND_TRIAGE_ADVICE)->count());
    }
}
