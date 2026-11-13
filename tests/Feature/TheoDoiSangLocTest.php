<?php

namespace Tests\Feature;

use App\Mail\TheoDoiSauSangLocMail;
use App\Models\NhatKyKiemToan;
use App\Models\DanhMuc;
use App\Models\TinNhanChat;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use App\Services\Triage\TheoDoiSangLocService;
use Database\Seeders\TriThucYTeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Safety-netting is a pharmacy-practice requirement, not a nice-to-have: a
 * self-care outcome must be re-checked, and "not improved" must escalate to a
 * doctor without offering more medicine.
 */
class TheoDoiSangLocTest extends TestCase
{
    use RefreshDatabase;

    protected TheoDoiSangLocService $service;
    protected DanhMuc $danhMuc;
    protected NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        $this->danhMuc = DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt',
            'mo_ta' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(TriThucYTeSeeder::class);

        $this->service = app(TheoDoiSangLocService::class);

        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active',
            'ngay_sinh' => now()->subYears(34)->toDateString(),
        ]);
    }

    // --- Scheduling ---

    public function test_chi_phien_tu_cham_soc_duoc_xep_lich_theo_doi(): void
    {
        // Drive the real hoanTat() so the scheduling rule is asserted, not the fixture.
        $triage = app(\App\Services\Triage\SangLocYTeService::class);

        $selfCare = $triage->hoanTat(
            $this->triageSession(['outcome' => null, 'status' => 'in_progress', 'follow_up_at' => null]),
            ['outcome' => 'self_care', 'advice_given' => 'Nghỉ ngơi.', 'safety_net' => 'Đau nặng thì đi khám.']
        );
        $refer = $triage->hoanTat(
            $this->triageSession(['outcome' => null, 'status' => 'in_progress', 'follow_up_at' => null]),
            ['outcome' => 'refer_doctor']
        );
        $emergency = $triage->hoanTat(
            $this->triageSession(['outcome' => null, 'status' => 'in_progress', 'follow_up_at' => null]),
            ['outcome' => 'emergency']
        );

        $this->assertNotNull($selfCare->follow_up_at);
        $this->assertNull($refer->follow_up_at);
        $this->assertNull($emergency->follow_up_at);
    }

    public function test_gui_dung_han_chi_chon_phien_qua_han(): void
    {
        Mail::fake();

        $due = $this->triageSession(['follow_up_at' => now()->subHour()]);
        $notYet = $this->triageSession(['follow_up_at' => now()->addDays(2)]);
        $inProgress = $this->triageSession([
            'follow_up_at' => now()->subHour(),
            'status' => 'in_progress',
        ]);

        $sent = $this->service->guiDungHan();

        $this->assertSame(1, $sent);
        $this->assertNull($due->fresh()->follow_up_at);
        $this->assertNotNull($notYet->fresh()->follow_up_at);
        $this->assertNotNull($inProgress->fresh()->follow_up_at);
    }

    public function test_theo_doi_khong_bao_gio_bi_hoi_lan_thu_hai(): void
    {
        Mail::fake();
        $this->triageSession(['follow_up_at' => now()->subHour()]);

        $this->assertSame(1, $this->service->guiDungHan());
        $this->assertSame(0, $this->service->guiDungHan());
        Mail::assertQueued(TheoDoiSauSangLocMail::class, 1);
    }

    public function test_gui_dung_han_gui_mail_cho_khach_va_ghi_tin_nhan_chat(): void
    {
        Mail::fake();
        $phien = $this->triageSession(['follow_up_at' => now()->subHour()]);

        $this->service->guiDungHan();

        Mail::assertQueued(TheoDoiSauSangLocMail::class);
        $this->assertDatabaseHas('tin_nhan_chats', [
            'ma_nd' => $this->nguoiDung->ma_nd,
            'session_id' => $phien->session_id,
            'role' => 'assistant',
        ]);
        $this->assertDatabaseHas('nhat_ky_kiem_toans', ['action' => 'triage_follow_up_sent']);
    }

    public function test_phien_khong_co_luot_chat_van_nhan_duoc_mail(): void
    {
        Mail::fake();
        $this->triageSession(['follow_up_at' => now()->subHour(), 'session_id' => null]);

        $this->assertSame(1, $this->service->guiDungHan());
        Mail::assertQueued(TheoDoiSauSangLocMail::class);
        $this->assertSame(0, TinNhanChat::count());
    }

    public function test_lenh_console_dispatch_theo_doi(): void
    {
        Mail::fake();
        $this->triageSession(['follow_up_at' => now()->subHour()]);

        $this->artisan('triage:follow-up')->assertSuccessful();

        Mail::assertQueued(TheoDoiSauSangLocMail::class);
    }

    // --- The core safety-netting rule ---

    public function test_khong_khoi_tiem_khuyen_kham_bac_si_va_tra_ve_khong_san_pham(): void
    {
        $phien = $this->triageSession(['follow_up_at' => null]);

        $result = $this->service->ghiPhanHoi($phien, false);

        $this->assertFalse($result['improved']);
        $this->assertTrue($result['refer_doctor']);
        // Hard assertion: a customer who did not get better must never be upsold.
        $this->assertSame([], $result['products']);
        $this->assertSame(__('messages.reminder.response_not_improved'), $result['message']);
        // The wording itself is the safety net, so assert the copy, not just the key.
        // Read from lang/vi/reminder.php directly: messages.php wiring is owned elsewhere.
        $this->assertStringContainsString('đi khám', $this->reminderLang('response_not_improved'));
        $this->assertSame('refer_doctor', $phien->fresh()->outcome);

        $tinNhan = TinNhanChat::where('session_id', $phien->session_id)->latest('ma_tn')->firstOrFail();
        $this->assertSame('assistant', $tinNhan->role);
        $this->assertEmpty($tinNhan->products ?? []);

        $this->assertDatabaseHas('nhat_ky_kiem_toans', ['action' => 'triage_follow_up_not_improved']);
    }

    public function test_da_khoi_duoc_ghi_nhan_va_ghi_kiem_toan(): void
    {
        $phien = $this->triageSession(['follow_up_at' => null]);

        $result = $this->service->ghiPhanHoi($phien, true);

        $this->assertTrue($result['improved']);
        $this->assertFalse($result['refer_doctor']);
        $this->assertSame([], $result['products']);
        $this->assertSame(__('messages.reminder.response_improved'), $result['message']);
        $this->assertStringContainsString('liệu trình', $this->reminderLang('response_improved'));
        $this->assertSame('self_care', $phien->fresh()->outcome);

        $this->assertDatabaseHas('nhat_ky_kiem_toans', ['action' => 'triage_follow_up_improved']);
    }

    public function test_ca_hai_nhanh_deu_ghi_ban_kiem_toan(): void
    {
        $this->service->ghiPhanHoi($this->triageSession(['follow_up_at' => null]), true);
        $this->service->ghiPhanHoi($this->triageSession(['follow_up_at' => null]), false);

        $this->assertSame(1, NhatKyKiemToan::where('action', 'triage_follow_up_improved')->count());
        $this->assertSame(1, NhatKyKiemToan::where('action', 'triage_follow_up_not_improved')->count());
    }

    // --- Ownership ---

    public function test_khach_khong_tra_loi_duoc_phien_cua_nguoi_khac(): void
    {
        $other = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
        $phien = $this->triageSession(['follow_up_at' => null]);

        $this->actingAs($other)
            ->post(route('sang-loc.theo-doi.tra-loi', $phien->ma_sl), ['improved' => 0])
            ->assertRedirect();

        $this->assertDatabaseMissing('nhat_ky_kiem_toans', ['action' => 'triage_follow_up_not_improved']);
        $this->assertSame('self_care', $phien->fresh()->outcome);
    }

    public function test_chu_phien_tra_loi_duoc_qua_route(): void
    {
        $phien = $this->triageSession(['follow_up_at' => null]);

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.theo-doi.tra-loi', $phien->ma_sl), ['improved' => 0])
            ->assertRedirect();

        $this->assertDatabaseHas('nhat_ky_kiem_toans', ['action' => 'triage_follow_up_not_improved']);
    }

    public function test_co_cho_phep_bat_buoc(): void
    {
        $phien = $this->triageSession(['follow_up_at' => null]);

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.theo-doi.tra-loi', $phien->ma_sl), [])
            ->assertSessionHasErrors('improved');
    }

    public function test_khach_chua_dang_nhap_khong_tra_loi_duoc_theo_doi(): void
    {
        $phien = $this->triageSession(['follow_up_at' => null]);

        $this->post(route('sang-loc.theo-doi.tra-loi', $phien->ma_sl), ['improved' => 1])
            ->assertRedirect(route('login'));
    }

    // --- helpers ---

    /**
     * lang/vi/reminder.php is not wired into messages.php yet (that file is owned
     * by another change), so assert the copy from the source file.
     */
    private function reminderLang(string $key): string
    {
        static $lines = null;
        $lines ??= require base_path('lang/vi/reminder.php');

        return $lines[$key];
    }

    private function triageSession(array $attributes = []): PhienSangLoc
    {
        return PhienSangLoc::create(array_merge([
            'ma_nd' => $this->nguoiDung->ma_nd,
            'session_id' => (string) Str::uuid(),
            'collected_data' => ['raw_symptom_text' => 'đau đầu nhẹ'],
            'outcome' => 'self_care',
            'advice_given' => 'Nghỉ ngơi, uống đủ nước.',
            'safety_net' => 'Đi khám ngay nếu đau đầu dữ dội.',
            'suggested_products' => [],
            'current_step' => 5,
            'entry_point' => 'page',
            'status' => 'completed',
        ], $attributes));
    }
}
