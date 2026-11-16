<?php

namespace Tests\Feature\Admin;

use App\Models\DanhMuc;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use App\Models\HoSoSucKhoe;
use App\Services\Triage\BaoCaoSangLocService;
use Database\Seeders\TriThucYTeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BaoCaoSangLocTest extends TestCase
{
    use RefreshDatabase;

    protected NguoiDung $admin;
    protected NguoiDung $customer;
    protected NguoiDung $pharmacist;

    protected function setUp(): void
    {
        parent::setUp();

        // The knowledge seeder resolves catalog categories by name, so it must exist first.
        DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt',
            'mo_ta' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(TriThucYTeSeeder::class);

        $this->admin = NguoiDung::factory()->create(['vai_tro' => 'Administrator', 'trang_thai' => 'active']);
        $this->customer = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
        $this->pharmacist = NguoiDung::factory()->create(['vai_tro' => 'Pharmacist', 'trang_thai' => 'active']);
    }

    private function makeSession(array $attributes = []): PhienSangLoc    {
        return PhienSangLoc::create(array_merge([
            'ma_nd' => $this->customer->ma_nd,
            'collected_data' => [
                'raw_symptom_text' => 'đau đầu hai ngày',
                'duration_days' => 2,
                'medications_taken' => 'chưa dùng gì',
                'skipped' => [],
            ],
            'matched_symptoms' => ['headache'],
            'triggered_red_flags' => [],
            'outcome' => 'self_care',
            'possible_condition_groups' => ['đau đầu căng cơ'],
            'advice_given' => 'Nghỉ ngơi và uống đủ nước.',
            'safety_net' => 'Đi khám nếu đau đầu dữ dội.',
            'current_step' => 5,
            'entry_point' => 'page',
            'status' => 'completed',
        ], $attributes));
    }

    private function seedAllOutcomes(): void
    {
        $this->makeSession(['outcome' => 'self_care']);
        $this->makeSession(['outcome' => 'pharmacist_review', 'status' => 'pending_pharmacist']);
        $this->makeSession(['outcome' => 'refer_doctor']);
        $this->makeSession([
            'outcome' => 'emergency',
            'triggered_red_flags' => [
                ['code' => 'stiff_neck_fever', 'name_vi' => 'sốt kèm cứng cổ', 'severity' => 'emergency'],
            ],
        ]);
    }

    // --- Access control ---

    public function test_quan_tri_vien_co_the_mo_bao_cao(): void
    {
        $this->makeSession();

        $this->actingAs($this->admin)
            ->get(route('admin.bao-cao-sang-loc.index'))
            ->assertOk()
            ->assertSee('Báo cáo sàng lọc y tế');
    }

    public function test_khach_hang_bi_tu_choi_truy_cap(): void
    {
        $this->actingAs($this->customer)
            ->get(route('admin.bao-cao-sang-loc.index'))
            ->assertForbidden();
    }

    public function test_duoc_si_bi_tu_choi_truy_cap(): void
    {
        // Pharmacists work their own review queue; the aggregate report is admin-only.
        $this->actingAs($this->pharmacist)
            ->get(route('admin.bao-cao-sang-loc.index'))
            ->assertForbidden();
    }

    public function test_khach_chua_dang_nhap_duoc_chuyen_den_trang_dang_nhap(): void
    {
        $this->get(route('admin.bao-cao-sang-loc.index'))->assertRedirect(route('login'));
    }

    // --- Filtering ---

    public function test_bo_loc_ket_qua_chi_tra_ve_cac_phien_phu_hop(): void
    {
        $selfCare = $this->makeSession(['outcome' => 'self_care']);
        $emergency = $this->makeSession(['outcome' => 'emergency']);

        $this->actingAs($this->admin)
            ->get(route('admin.bao-cao-sang-loc.index', ['outcome' => 'emergency']))
            ->assertOk()
            ->assertSee('SL-' . $emergency->ma_sl)
            ->assertDontSee('SL-' . $selfCare->ma_sl);
    }

    public function test_bo_loc_khoang_ngay_thu_hep_ket_qua(): void
    {
        $old = $this->makeSession();
        $old->forceFill(['created_at' => now()->subDays(20)])->save();

        $recent = $this->makeSession();

        $this->actingAs($this->admin)
            ->get(route('admin.bao-cao-sang-loc.index', [
                'date_from' => now()->subDays(3)->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('SL-' . $recent->ma_sl)
            ->assertDontSee('SL-' . $old->ma_sl);
    }

    // --- Service metrics ---

    public function test_tong_hop_dem_day_du_cac_ket_qua(): void
    {
        $this->seedAllOutcomes();

        $summary = app(BaoCaoSangLocService::class)->layTongHop();

        $this->assertSame(4, $summary['total']);
        $this->assertSame(1, $summary['outcomes']['self_care']);
        $this->assertSame(1, $summary['outcomes']['pharmacist_review']);
        $this->assertSame(1, $summary['outcomes']['refer_doctor']);
        $this->assertSame(1, $summary['outcomes']['emergency']);
        $this->assertSame(50.0, $summary['referral_rate']);
        $this->assertSame(1, $summary['pending_pharmacist']);
    }

    public function test_thoi_gian_cho_trung_binh_chi_dung_cac_phien_da_duyet(): void
    {
        $reviewed = $this->makeSession(['status' => 'reviewed', 'pharmacist_id' => $this->pharmacist->ma_nd]);
        $reviewed->forceFill([
            'created_at' => now()->subMinutes(30),
            'reviewed_at' => now(),
        ])->save();

        $this->makeSession(['status' => 'completed']);

        $summary = app(BaoCaoSangLocService::class)->layTongHop();

        $this->assertSame(1, $summary['reviewed_count']);
        $this->assertEqualsWithDelta(30.0, $summary['average_wait_minutes'], 1.0);
    }

    public function test_xu_huong_hang_ngay_dien_khong_vao_ngay_khong_co_du_lieu(): void
    {
        $this->makeSession();

        $trend = app(BaoCaoSangLocService::class)->layXuHuongNgay(7);

        $this->assertCount(7, $trend['labels']);
        $this->assertCount(7, $trend['data']);
        $this->assertSame(1, $trend['data'][6]);
        $this->assertSame([0, 0, 0, 0, 0, 0], array_slice($trend['data'], 0, 6));
    }

    public function test_trieu_chung_pho_bien_dich_ma_sang_tieng_viet(): void
    {
        $this->makeSession(['matched_symptoms' => ['headache']]);
        $this->makeSession(['matched_symptoms' => ['headache', 'fever']]);

        $top = app(BaoCaoSangLocService::class)->layTrieuChungNhieu();

        $this->assertSame('headache', $top[0]['code']);
        $this->assertSame('đau đầu', $top[0]['name_vi']);
        $this->assertSame(2, $top[0]['count']);
    }

    public function test_phan_bo_nguon_vao_dem_ca_hai_nguon(): void
    {
        $this->makeSession(['entry_point' => 'chat']);
        $this->makeSession(['entry_point' => 'page']);
        $this->makeSession(['entry_point' => 'page']);

        $split = app(BaoCaoSangLocService::class)->layPhanBoNguonVao();

        $this->assertSame(1, $split['chat']);
        $this->assertSame(2, $split['page']);
    }

    // --- Charts & export ---

    public function test_diem_cuoi_bieu_do_ket_qua_tra_ve_dung_cau_truc(): void
    {
        $this->seedAllOutcomes();

        $this->actingAs($this->admin)
            ->getJson(route('admin.bao-cao-sang-loc.outcomeChart'))
            ->assertOk()
            ->assertJsonStructure([
                'labels',
                'data',
                'colors',
                'rows' => [['outcome', 'label', 'count', 'color', 'icon']],
            ]);
    }

    public function test_diem_cuoi_bieu_do_xu_huong_gioi_han_so_ngay(): void
    {
        $this->makeSession();

        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.bao-cao-sang-loc.trendChart', ['days' => 5000]))
            ->assertOk()
            ->assertJsonStructure(['labels', 'data', 'days']);

        $this->assertSame(365, $response->json('days'));
        $this->assertCount(365, $response->json('data'));
    }

    public function test_xuat_bao_cao_tra_ve_tep_excel(): void
    {
        $this->makeSession();

        $response = $this->actingAs($this->admin)->get(route('admin.bao-cao-sang-loc.export'));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('content-type')
        );
    }

    // --- Privacy ---

    public function test_trang_chi_tiet_khong_bao_gio_lo_ho_so_suc_khoe(): void
    {
        HoSoSucKhoe::create(['ma_nd' => $this->customer->ma_nd,
            'allergies' => ['Paracetamol'],
            'consented_at' => now(),
        ]);

        $phien = $this->makeSession();

        $this->actingAs($this->admin)
            ->get(route('admin.bao-cao-sang-loc.show', $phien->ma_sl))
            ->assertOk()
            ->assertSee('SL-' . $phien->ma_sl)
            ->assertDontSee('Paracetamol');
    }

    public function test_trang_chi_tiet_hien_trieu_chung_da_khop_va_ghi_chu_duoc_si(): void
    {
        $phien = $this->makeSession([
            'status' => 'reviewed',
            'pharmacist_id' => $this->pharmacist->ma_nd,
            'pharmacist_note' => 'Đã tư vấn qua điện thoại.',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.bao-cao-sang-loc.show', $phien->ma_sl))
            ->assertOk()
            ->assertSee('đau đầu')
            ->assertSee('Đã tư vấn qua điện thoại.');
    }

    public function test_hien_trang_thai_trong_khi_khong_co_phien_phu_hop(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.bao-cao-sang-loc.index'))
            ->assertOk()
            ->assertSee('Chưa có ca sàng lọc nào phù hợp bộ lọc hiện tại.');
    }
}
