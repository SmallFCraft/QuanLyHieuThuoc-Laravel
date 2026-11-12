<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use Database\Seeders\TriThucYTeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TriageBriefingPaneTest extends TestCase
{
    use RefreshDatabase;

    protected NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt', 'mo_ta' => 'Giảm đau, hạ sốt']);
        $this->seed(TriThucYTeSeeder::class);

        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
    }

    protected function completedSession(array $attributes = []): PhienSangLoc
    {
        return PhienSangLoc::create(array_merge([
            'ma_nd' => $this->nguoiDung->ma_nd,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'self_care',
            'advice_given' => 'Nghỉ ngơi, uống đủ nước.',
            'collected_data' => ['raw_symptom_text' => 'dau dau nhe', 'skipped' => []],
        ], $attributes));
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $phien = $this->completedSession();

        $this->get(route('health.result.briefing-pane', $phien->ma_sl))
            ->assertRedirect('/login');
    }

    public function test_another_customers_session_is_not_found(): void
    {
        $other = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
        $phien = $this->completedSession(['ma_nd' => $other->ma_nd]);

        $this->actingAs($this->nguoiDung)
            ->get(route('health.result.briefing-pane', $phien->ma_sl))
            ->assertNotFound();
    }

    public function test_an_emergency_session_has_no_briefing_pane(): void
    {
        $phien = $this->completedSession(['outcome' => 'emergency', 'advice_given' => null]);

        $this->actingAs($this->nguoiDung)
            ->get(route('health.result.briefing-pane', $phien->ma_sl))
            ->assertNotFound();
    }

    public function test_an_in_progress_session_has_no_briefing_pane(): void
    {
        $phien = $this->completedSession(['status' => 'in_progress', 'advice_given' => null]);

        $this->actingAs($this->nguoiDung)
            ->get(route('health.result.briefing-pane', $phien->ma_sl))
            ->assertNotFound();
    }

    public function test_a_pending_briefing_returns_202_with_the_skeleton(): void
    {
        $phien = $this->completedSession(['ai_briefing' => null, 'ai_briefing_status' => null]);

        $this->actingAs($this->nguoiDung)
            ->get(route('health.result.briefing-pane', $phien->ma_sl))
            ->assertStatus(202)
            ->assertSee('health-skeleton', false);
    }

    public function test_a_finished_job_without_status_returns_200_unavailable_instead_of_looping_202(): void
    {
        // A worker that finished without recording a status (or where generate failed
        // to write columns) leaves status null while the job itself is done. The poller
        // sees `done` and hits the pane; if the pane returned 202 it would loop until
        // the 90-attempt cap. It must treat a finished job as definitively done.
        $phien = $this->completedSession(['ai_briefing' => null, 'ai_briefing_status' => null]);
        \App\Models\AiJob::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'ma_nd' => $this->nguoiDung->ma_nd,
            'kind' => \App\Models\AiJob::KIND_TRIAGE_BRIEFING,
            'status' => 'done',
            'payload' => ['ma_sl' => $phien->ma_sl],
        ]);

        $this->actingAs($this->nguoiDung)
            ->get(route('health.result.briefing-pane', $phien->ma_sl))
            ->assertOk()
            ->assertSee(__('messages.health.briefing_unavailable'), false);
    }

    public function test_a_finished_briefing_returns_200_with_its_sections(): void
    {
        $phien = $this->completedSession([
            'ai_briefing' => [
                'why' => 'Đau đầu nhẹ thường gặp khi thiếu ngủ.',
                'self_care' => ['Nghỉ ngơi trong phòng yên tĩnh.'],
                'products' => [],
            ],
            'ai_briefing_status' => 'ok',
        ]);

        $this->actingAs($this->nguoiDung)
            ->get(route('health.result.briefing-pane', $phien->ma_sl))
            ->assertOk()
            ->assertSee('Đau đầu nhẹ thường gặp khi thiếu ngủ.', false)
            ->assertSee('briefing-self-care', false)
            ->assertSee('<template id="briefing-care-products">', false);
    }

    public function test_a_failed_briefing_returns_200_with_the_unavailable_notice(): void
    {
        $phien = $this->completedSession(['ai_briefing' => null, 'ai_briefing_status' => 'failed']);

        $this->actingAs($this->nguoiDung)
            ->get(route('health.result.briefing-pane', $phien->ma_sl))
            ->assertOk()
            ->assertSee(__('messages.health.briefing_unavailable'), false);
    }

    public function test_a_finished_briefing_embeds_ai_selected_products_in_the_care_template(): void
    {
        $danhMuc = DanhMuc::first();
        $sanPham = SanPham::create(['ma_dm' => $danhMuc->ma_dm,
            'ten' => 'Thuốc ho Prospan 100ml',
            'thanh_phan' => 'Cao lá thường xuân',
            'cach_dung' => 'Uống 5ml mỗi ngày 3 lần',
            'gia' => 120000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 15,
            'muc_canh_bao' => 2,
            'so_lo' => 'B-PROSPAN',
        ]);

        $phien = $this->completedSession([
            'suggested_products' => [],
            'ai_briefing' => [
                'why' => 'Ho có đờm cần thuốc long đờm thảo dược.',
                'self_care' => ['Uống nhiều nước ấm.'],
                'products' => [[
                    'id' => $sanPham->ma_sp,
                    'name' => 'Thuốc ho Prospan 100ml',
                    'price' => 120000,
                    'stock_quantity' => 15,
                    'reason' => 'Giúp giảm ho dịu họng an toàn.',
                ]],
            ],
            'ai_briefing_status' => 'ok',
        ]);

        $content = $this->actingAs($this->nguoiDung)
            ->get(route('health.result.briefing-pane', $phien->ma_sl))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<template id="briefing-care-products">', $content);
        $template = substr($content, (int) strpos($content, '<template id="briefing-care-products">'));
        $this->assertStringContainsString('Thuốc ho Prospan 100ml', $template);
        $this->assertStringContainsString('120.000 ₫', $template);
        $this->assertStringContainsString('Giúp giảm ho dịu họng an toàn.', $template);
    }
}
