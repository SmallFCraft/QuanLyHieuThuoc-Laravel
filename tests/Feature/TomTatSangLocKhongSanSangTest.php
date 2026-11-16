<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\DanhMuc;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use Database\Seeders\TriThucYTeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the result page shows while the briefing is being written, and what it shows
 * when that write never lands.
 *
 * The defect this pins: the poller's stop() removed the skeleton and put nothing in
 * its place, so a dead worker left a gap where the explanation should be — a page
 * that reads as though it is still loading, forever. The advice banner is the
 * opposite case and must keep being removed outright: its text is already on the
 * page, so a notice there would be noise.
 */
class TomTatSangLocKhongSanSangTest extends TestCase
{
    use RefreshDatabase;

    protected NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt', 'mo_ta' => 'Giảm đau, hạ sốt']);
        $this->seed(TriThucYTeSeeder::class);

        config()->set('chatbot.ai.triage.briefing', true);

        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
    }

    public function test_briefing_dang_cho_gui_thong_bao_khong_san_sang_o_trang_thai_an(): void
    {
        $phien = $this->completedSession();
        $this->queuedBriefing($phien);

        $html = $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->assertSee('triage-briefing-pending', false)
            ->assertSee('triage-briefing-unavailable', false)
            ->assertSee(__('messages.health.briefing_unavailable'), false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/id="triage-briefing-unavailable"[^>]*\shidden/',
            $html,
            'The notice must ship hidden so the poller can reveal it without a round trip.'
        );
    }

    /** The poller needs the id to unhide, and only for the briefing. */
    public function test_chi_job_poll_briefing_mang_dich_den_du_phong(): void
    {
        $phien = $this->completedSession();
        $this->queuedBriefing($phien);
        AiJob::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'ma_nd' => $phien->ma_nd,
            'kind' => AiJob::KIND_TRIAGE_ADVICE,
            'status' => 'queued',
            'payload' => ['ma_sl' => $phien->ma_sl],
        ]);

        $html = $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('triage-briefing-unavailable', $html);
        // The advice banner's text is already on the page; a notice there is noise.
        $this->assertStringNotContainsString('triage-ai-unavailable', $html);
    }

    /** A briefing already attempted and abandoned shows the notice outright. */
    public function test_luot_thu_da_ket_thuc_khong_co_briefing_hien_thong_bao_o_trang_thai_hien(): void
    {
        $phien = $this->completedSession(['ai_briefing_status' => 'failed']);

        $html = $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->assertSee(__('messages.health.briefing_unavailable'), false)
            ->getContent();

        $this->assertStringNotContainsString('triage-briefing-pending', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/id="triage-briefing-unavailable"[^>]*\shidden/',
            $html,
            'Nothing is polling any more, so the notice must be visible immediately.'
        );
    }

    /** An emergency page was never due one, so it must not apologise for the absence. */
    public function test_trang_khan_cap_khong_hien_thong_bao_briefing(): void
    {
        $phien = $this->completedSession([
            'outcome' => 'emergency',
            'advice_given' => 'Anh/chị hãy gọi ngay 115.',
        ]);

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->assertDontSee(__('messages.health.briefing_unavailable'), false)
            ->assertDontSee('triage-briefing-pending', false);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function completedSession(array $attributes = []): PhienSangLoc
    {
        return PhienSangLoc::create(array_merge([
            'ma_nd' => $this->nguoiDung->ma_nd,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'self_care',
            'advice_given' => 'Nghỉ ngơi, uống đủ nước.',
            'safety_net' => 'Đi khám nếu nặng hơn.',
            'collected_data' => ['raw_symptom_text' => 'dau dau nhe', 'skipped' => []],
        ], $attributes));
    }

    /**
     * A briefing job the page will poll. `ai_briefing_status` stays null so
     * ensureBriefingJob() finds this row instead of queueing a second one.
     */
    protected function queuedBriefing(PhienSangLoc $phien): AiJob
    {
        return AiJob::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'ma_nd' => $phien->ma_nd,
            'kind' => AiJob::KIND_TRIAGE_BRIEFING,
            'status' => 'queued',
            'payload' => ['ma_sl' => $phien->ma_sl],
        ]);
    }
}
