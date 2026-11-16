<?php

namespace Tests\Feature;

use App\Models\NhatKyKiemToan;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pharmacist queue as an opt-in side channel.
 *
 * AI is the primary layer of the feature: finalising a session never queues it, and
 * a human is brought in only when the customer asks. Emergencies are excluded —
 * their only action is calling 115.
 */
class YeuCauDuocSiSangLocTest extends TestCase
{
    use RefreshDatabase;

    protected NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
    }

    protected function completedSession(array $attributes = []): PhienSangLoc
    {
        return PhienSangLoc::create(array_merge([
            'ma_nd' => $this->nguoiDung->ma_nd,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'pharmacist_review',
            'advice_given' => 'Anh/chị theo dõi thêm và đi khám nếu nặng lên.',
            'collected_data' => ['raw_symptom_task' => 'x', 'raw_symptom_text' => 'dau dau'],
        ], $attributes));
    }

    public function test_hang_doi_trong_cho_den_khi_khach_hang_yeu_cau(): void
    {
        $pharmacist = NguoiDung::factory()->create(['vai_tro' => 'Pharmacist', 'trang_thai' => 'active']);
        $this->completedSession();

        $this->actingAs($pharmacist)
            ->get(route('duoc-si.sang-loc.index'))
            ->assertOk()
            ->assertSee(__('messages.pharmacist.queue_empty'));
    }

    public function test_ca_duoc_yeu_cau_xuat_hien_trong_hang_doi(): void
    {
        $pharmacist = NguoiDung::factory()->create(['vai_tro' => 'Pharmacist', 'trang_thai' => 'active']);
        $phien = $this->completedSession();

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.xin-duoc-si', $phien->ma_sl))
            ->assertRedirect(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertSessionHas('success', __('messages.health.pharmacist_requested'));

        $this->assertSame('pending_pharmacist', $phien->fresh()->status);

        $this->actingAs($pharmacist)
            ->get(route('duoc-si.sang-loc.index'))
            ->assertOk()
            ->assertDontSee(__('messages.pharmacist.queue_empty'));
    }

    public function test_ca_tu_cham_soc_cung_co_the_yeu_cau_duoc_si(): void
    {
        $phien = $this->completedSession([
            'outcome' => 'self_care',
            'safety_net' => 'Đi khám nếu sốt quá 3 ngày.',
            'follow_up_at' => now()->addDays(3),
        ]);

        $this->actingAs($this->nguoiDung)->post(route('sang-loc.xin-duoc-si', $phien->ma_sl));

        $phien = $phien->fresh();

        $this->assertSame('pending_pharmacist', $phien->status);
        // A queued case waits on a human, so the automated check-in is cleared.
        $this->assertNull($phien->follow_up_at);
    }

    public function test_ca_cap_cuu_khong_the_vao_hang_doi(): void
    {
        $phien = $this->completedSession([
            'outcome' => 'emergency',
            'advice_given' => 'Anh/chị hãy gọi ngay 115.',
        ]);

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.xin-duoc-si', $phien->ma_sl))
            ->assertSessionHas('success', __('messages.health.pharmacist_request_unavailable'));

        $this->assertSame('completed', $phien->fresh()->status);
    }

    public function test_yeu_cau_lan_hai_khong_lam_gi(): void
    {
        $phien = $this->completedSession();

        $this->actingAs($this->nguoiDung)->post(route('sang-loc.xin-duoc-si', $phien->ma_sl));
        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.xin-duoc-si', $phien->ma_sl))
            ->assertSessionHas('success', __('messages.health.pharmacist_request_unavailable'));

        $this->assertSame('pending_pharmacist', $phien->fresh()->status);
        $this->assertSame(1, NhatKyKiemToan::where('action', 'triage_pharmacist_requested')->count());
    }

    public function test_phien_dang_xu_ly_khong_the_vao_hang_doi(): void
    {
        $phien = $this->completedSession(['status' => 'in_progress', 'outcome' => null]);

        $this->actingAs($this->nguoiDung)->post(route('sang-loc.xin-duoc-si', $phien->ma_sl));

        $this->assertSame('in_progress', $phien->fresh()->status);
    }

    public function test_yeu_cau_duoc_ghi_nhat_ky_chi_bang_ma(): void
    {
        $phien = $this->completedSession(['matched_symptoms' => ['headache']]);

        $this->actingAs($this->nguoiDung)->post(route('sang-loc.xin-duoc-si', $phien->ma_sl));

        $log = NhatKyKiemToan::where('action', 'triage_pharmacist_requested')->firstOrFail();
        $encoded = json_encode($log->new_values, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('headache', $encoded);
        $this->assertStringNotContainsString('dau dau', $encoded);
    }

    public function test_nguoi_dung_khac_khong_the_xep_hang_phien_cua_nguoi_khac(): void
    {
        $phien = $this->completedSession();
        $other = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);

        $this->actingAs($other)
            ->post(route('sang-loc.xin-duoc-si', $phien->ma_sl))
            ->assertNotFound();

        $this->assertSame('completed', $phien->fresh()->status);
    }

    public function test_khach_chua_dang_nhap_khong_the_xep_hang_phien(): void
    {
        $phien = $this->completedSession();

        $this->post(route('sang-loc.xin-duoc-si', $phien->ma_sl))
            ->assertRedirect(route('login'));
    }
}
