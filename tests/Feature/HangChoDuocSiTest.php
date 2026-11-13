<?php

namespace Tests\Feature;

use App\Models\NhatKyKiemToan;
use App\Models\DanhMuc;
use App\Models\TinNhanChat;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use App\Models\HoSoSucKhoe;
use Database\Seeders\TriThucYTeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The pharmacist queue is the only place a real human closes a triage case.
 * These tests pin the access boundary, the ordering promise (longest wait first)
 * and the rule that a reviewed case is never silently overwritten.
 */
class HangChoDuocSiTest extends TestCase
{
    use RefreshDatabase;

    protected NguoiDung $pharmacist;
    protected NguoiDung $customer;

    protected function setUp(): void
    {
        parent::setUp();

        DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt',
            'mo_ta' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(TriThucYTeSeeder::class);

        $this->pharmacist = NguoiDung::factory()->create(['vai_tro' => 'Pharmacist',
            'trang_thai' => 'active',
        ]);

        $this->customer = NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active',
            'ngay_sinh' => now()->subYears(41)->toDateString(),
        ]);
    }

    // --- Access control ---

    public function test_duoc_si_mo_duoc_hang_cho(): void
    {
        $this->actingAs($this->pharmacist)
            ->get(route('duoc-si.sang-loc.index'))
            ->assertOk()
            ->assertSee(__('messages.pharmacist.queue_title'));
    }

    public function test_nguoi_dung_thuong_bi_cam(): void
    {
        $this->actingAs($this->customer)
            ->get(route('duoc-si.sang-loc.index'))
            ->assertForbidden();
    }

    public function test_quan_tri_vien_cung_bi_cam(): void
    {
        // Running the shop is not the same job as answering clinical questions.
        $admin = NguoiDung::factory()->create(['vai_tro' => 'Administrator', 'trang_thai' => 'active']);

        $this->actingAs($admin)
            ->get(route('duoc-si.sang-loc.index'))
            ->assertForbidden();
    }

    public function test_khach_vo_danh_bi_chuyen_den_dang_nhap(): void
    {
        $this->get(route('duoc-si.sang-loc.index'))->assertRedirect(route('login'));
    }

    public function test_duoc_si_khong_hoat_dong_bi_chan(): void
    {
        $inactive = NguoiDung::factory()->create(['vai_tro' => 'Pharmacist', 'trang_thai' => 'inactive']);

        $this->actingAs($inactive)
            ->get(route('duoc-si.sang-loc.index'))
            ->assertRedirect(route('login'));
    }

    // --- Queue contents and ordering ---

    public function test_hang_cho_chi_liet_ke_ca_dang_cho(): void
    {
        $pending = $this->pendingSession(['raw_symptom_text' => 'đau đầu âm ỉ nhiều ngày']);
        $completed = $this->makeSession('completed', ['raw_symptom_text' => 'ho nhẹ đã khỏi']);
        $inProgress = $this->makeSession('in_progress', ['raw_symptom_text' => 'chưa trả lời xong']);

        $response = $this->actingAs($this->pharmacist)
            ->get(route('duoc-si.sang-loc.index'))
            ->assertOk();

        $response->assertSee(route('duoc-si.sang-loc.show', $pending->ma_sl), false);
        $response->assertDontSee(route('duoc-si.sang-loc.show', $completed->ma_sl), false);
        $response->assertDontSee(route('duoc-si.sang-loc.show', $inProgress->ma_sl), false);
    }

    public function test_ca_cu_nhat_xuat_hien_dau_tien(): void
    {
        $newer = $this->pendingSession(['raw_symptom_text' => 'ca moi vao'], now()->subMinutes(5));
        $older = $this->pendingSession(['raw_symptom_text' => 'ca cho lau nhat'], now()->subHours(3));

        $html = $this->actingAs($this->pharmacist)
            ->get(route('duoc-si.sang-loc.index'))
            ->assertOk()
            ->getContent();

        $olderPos = strpos($html, route('duoc-si.sang-loc.show', $older->ma_sl));
        $newerPos = strpos($html, route('duoc-si.sang-loc.show', $newer->ma_sl));

        $this->assertNotFalse($olderPos);
        $this->assertNotFalse($newerPos);
        $this->assertLessThan($newerPos, $olderPos, 'Whoever waited longest must be handled first.');
    }

    public function test_hang_cho_rong_hien_thong_bao_phu_hop(): void
    {
        $this->actingAs($this->pharmacist)
            ->get(route('duoc-si.sang-loc.index'))
            ->assertOk()
            ->assertSee(__('messages.pharmacist.queue_empty'));
    }

    // --- Reply ---

    public function test_tra_loi_ghi_nhan_xet_duyet(): void
    {
        $phien = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.reply', $phien->ma_sl), [
                'pharmacist_note' => 'Anh/chị có thể dùng paracetamol 500mg khi đau, tối đa 4 lần/ngày.',
            ])
            ->assertRedirect(route('duoc-si.sang-loc.index'));

        $phien->refresh();

        $this->assertSame($this->pharmacist->ma_nd, $phien->pharmacist_id);
        $this->assertStringContainsString('paracetamol', $phien->pharmacist_note);
        $this->assertNotNull($phien->reviewed_at);
        $this->assertSame('reviewed', $phien->status);
    }

    public function test_tra_loi_ghi_tin_nhan_chat_duoc_danh_dau_da_xet_duyet_boi_nguoi(): void
    {
        $phien = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.reply', $phien->ma_sl), [
                'pharmacist_note' => 'Em đã xem, anh/chị theo dõi thêm 2 ngày nhé.',
            ]);

        $tinNhan = TinNhanChat::where('session_id', $phien->session_id)
            ->where('role', 'assistant')
            ->latest('ma_tn')
            ->first();

        $this->assertNotNull($tinNhan);
        $this->assertTrue($tinNhan->metadata['reviewed_by_pharmacist']);
        $this->assertSame($this->pharmacist->ma_nd, $tinNhan->metadata['pharmacist_id']);
        $this->assertSame($phien->ma_sl, $tinNhan->metadata['triage_session_id']);
    }

    public function test_tra_loi_ca_da_xet_duyet_bi_tu_choi(): void
    {
        $phien = $this->pendingSession();
        $phien->update([
            'status' => 'reviewed',
            'pharmacist_note' => 'Ghi chú gốc của dược sĩ trước.',
            'pharmacist_id' => $this->pharmacist->ma_nd,
            'reviewed_at' => now()->subHour(),
        ]);

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.reply', $phien->ma_sl), [
                'pharmacist_note' => 'Ghi chú mới không được phép ghi đè.',
            ])
            ->assertSessionHasErrors('pharmacist_note');

        $this->assertSame('Ghi chú gốc của dược sĩ trước.', $phien->fresh()->pharmacist_note);
    }

    public function test_tra_loi_khong_ghi_chu_bi_tu_choi(): void
    {
        $phien = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.reply', $phien->ma_sl), ['pharmacist_note' => ''])
            ->assertSessionHasErrors('pharmacist_note');

        $this->assertSame('pending_pharmacist', $phien->fresh()->status);
    }

    // --- Escalate ---

    public function test_chuyen_tuyen_khuyen_di_bac_si_va_bo_goi_y_san_pham(): void
    {
        $phien = $this->pendingSession();
        $phien->update([
            'outcome' => 'pharmacist_review',
            'suggested_products' => [['name' => 'Paracetamol 500mg', 'price' => 5000]],
        ]);

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.escalate', $phien->ma_sl), [
                'pharmacist_note' => 'Triệu chứng kéo dài kèm sụt cân, anh/chị cần bác sĩ khám trực tiếp.',
            ])
            ->assertRedirect(route('duoc-si.sang-loc.index'));

        $phien->refresh();

        $this->assertSame('refer_doctor', $phien->outcome);
        $this->assertSame([], $phien->suggested_products);
        $this->assertSame('reviewed', $phien->status);
    }

    // --- Health profile privacy ---

    public function test_chi_tiet_an_du_lieu_ho_so_khi_chua_dong_y(): void
    {
        HoSoSucKhoe::create(['ma_nd' => $this->customer->ma_nd,
            'allergies' => ['dị ứng penicillin'],
            'chronic_conditions' => ['loét dạ dày'],
            'consented_at' => null,
        ]);

        $phien = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->get(route('duoc-si.sang-loc.show', $phien->ma_sl))
            ->assertOk()
            ->assertSee(__('messages.pharmacist.profile_no_consent'))
            ->assertDontSee('dị ứng penicillin')
            ->assertDontSee('loét dạ dày');

        $this->assertSame(0, NhatKyKiemToan::where('action', 'health_profile_accessed')->count());
    }

    public function test_chi_tiet_hien_ho_so_da_dong_y_va_ghi_nhat_ky_truy_cap(): void
    {
        HoSoSucKhoe::create(['ma_nd' => $this->customer->ma_nd,
            'allergies' => ['dị ứng penicillin'],
            'consented_at' => now(),
        ]);

        $phien = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->get(route('duoc-si.sang-loc.show', $phien->ma_sl))
            ->assertOk()
            ->assertSee('dị ứng penicillin');

        $this->assertDatabaseHas('nhat_ky_kiem_toans', [
            'action' => 'health_profile_accessed',
            'ma_nd' => $this->pharmacist->ma_nd,
            'model_type' => HoSoSucKhoe::class,
        ]);
    }

    // --- Claim ---

    public function test_nhan_ca_chan_duoc_si_thu_hai(): void
    {
        $other = NguoiDung::factory()->create(['vai_tro' => 'Pharmacist', 'trang_thai' => 'active']);
        $phien = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.claim', $phien->ma_sl))
            ->assertRedirect(route('duoc-si.sang-loc.index'));

        $this->assertSame($this->pharmacist->ma_nd, $phien->fresh()->pharmacist_id);

        $this->actingAs($other)
            ->post(route('duoc-si.sang-loc.reply', $phien->ma_sl), [
                'pharmacist_note' => 'Dược sĩ khác cố trả lời cùng ca.',
            ])
            ->assertSessionHasErrors('pharmacist_note');

        $this->assertNull($phien->fresh()->pharmacist_note);
    }

    // --- Past sessions on the detail page ---

    public function test_trang_chi_tiet_tai_phien_gan_day_cua_cung_khach(): void
    {
        $past = $this->makeSession('reviewed', ['raw_symptom_text' => 'Triệu chứng cũ']);
        $current = $this->pendingSession(['raw_symptom_text' => 'Triệu chứng mới']);

        $this->actingAs($this->pharmacist)
            ->get(route('duoc-si.sang-loc.show', $current->ma_sl))
            ->assertOk()
            ->assertViewHas('pastSessions', fn ($sessions) => $sessions->contains('ma_sl', $past->ma_sl));
    }

    public function test_trang_chi_tiet_loai_phien_cu_cua_khach_khac(): void
    {
        $stranger = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
        $strangerSession = PhienSangLoc::create(['ma_nd' => $stranger->ma_nd,
            'status' => 'reviewed',
            'collected_data' => ['raw_symptom_text' => 'Ca của người khác'],
        ]);

        $current = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->get(route('duoc-si.sang-loc.show', $current->ma_sl))
            ->assertOk()
            ->assertViewHas('pastSessions', fn ($sessions) => !$sessions->contains('ma_sl', $strangerSession->ma_sl));
    }

    public function test_trang_chi_tiet_loai_phien_chua_tung_chuyen_duoc_si(): void
    {
        $inProgress = $this->makeSession('in_progress', ['raw_symptom_text' => 'Chưa trả lời xong']);
        $current = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->get(route('duoc-si.sang-loc.show', $current->ma_sl))
            ->assertOk()
            ->assertViewHas('pastSessions', fn ($sessions) => !$sessions->contains('ma_sl', $inProgress->ma_sl));
    }

    // Note: triage_sessions.MaKH is NOT NULL, so the empty-collection branch of
    // show() is unreachable through the DB and is left untested.

    // --- Queue metrics ---

    public function test_trang_hang_cho_bao_chi_so_ca_dang_cho(): void
    {
        $older = $this->pendingSession([], now()->subHours(4));
        $this->pendingSession([], now()->subMinutes(3));
        $this->makeSession('reviewed', [], now()->subDay());

        $this->actingAs($this->pharmacist)
            ->get(route('duoc-si.sang-loc.index'))
            ->assertOk()
            ->assertViewHas('pendingCount', 2)
            ->assertViewHas(
                'oldestPendingAt',
                fn ($oldest) => $oldest instanceof Carbon
                    && $oldest->equalTo($older->created_at)
            );
    }

    // --- Helpers ---

    protected function pendingSession(array $collected = [], ?\DateTimeInterface $createdAt = null): PhienSangLoc
    {
        return $this->makeSession('pending_pharmacist', $collected, $createdAt);
    }

    protected function makeSession(string $status, array $collected = [], ?\DateTimeInterface $createdAt = null): PhienSangLoc
    {
        $phien = PhienSangLoc::create(['ma_nd' => $this->customer->ma_nd,
            'session_id' => (string) Str::uuid(),
            'collected_data' => array_merge([
                'raw_symptom_text' => 'đau đầu kéo dài, chưa rõ nguyên nhân',
                'symptoms' => 'đau đầu',
                'duration_days' => 4,
                'medications_taken' => 'chưa dùng gì',
                'history' => 'không có',
                'other_symptoms' => 'không có thêm',
                'skipped' => [],
            ], $collected),
            'matched_symptoms' => ['headache'],
            'triggered_red_flags' => [
                ['code' => 'headache_persistent', 'name_vi' => 'đau đầu kéo dài', 'severity' => 'urgent'],
            ],
            'outcome' => 'pharmacist_review',
            'advice_given' => 'Em đã chuyển ca này cho dược sĩ xem.',
            'safety_net' => 'Đi khám ngay nếu đau đầu dữ dội hoặc nôn nhiều.',
            'current_step' => 6,
            'entry_point' => 'page',
            'status' => $status,
        ]);

        if ($createdAt) {
            // created_at drives the queue order, so it has to be settable per case.
            $phien->forceFill(['created_at' => $createdAt])->save();
        }

        return $phien->fresh();
    }
}
