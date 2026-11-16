<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\YeuThich;
use App\Models\NhacUongThuoc;
use App\Models\DonHang;
use App\Models\SanPham;
use App\Models\NguoiDung;
use App\Models\HoSoSucKhoe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trang tài khoản sau khi gộp style và thêm tab tổng quan.
 *
 * Trước đây mỗi partial mang một khối <style> riêng nên cùng một ô input có ba bán
 * kính bo góc tuỳ theo tab; và không có chỗ nào cho người dùng thấy tổng quan.
 */
class TrangHoSoTest extends TestCase
{
    use RefreshDatabase;

    private NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nguoiDung = NguoiDung::factory()->create(['ho_ten' => 'Nguyễn Văn An']);
    }

    public function test_trang_mo_o_tab_tong_quan(): void
    {
        $this->actingAs($this->nguoiDung)
            ->get(route('ho-so.edit'))
            ->assertOk()
            ->assertSee('id="overview"', false)
            ->assertSee('show active', false)
            ->assertSee(__('messages.profile.overview_title'));
    }

    public function test_moi_tab_deu_duoc_render(): void
    {
        $response = $this->actingAs($this->nguoiDung)->get(route('ho-so.edit'))->assertOk();

        foreach (['overview', 'profile-info', 'orders', 'favorites', 'security', 'health-profile', 'settings'] as $tab) {
            $response->assertSee('id="' . $tab . '"', false);
        }
    }

    public function test_thanh_ben_la_danh_sach_tab_de_bootstrap_chuyen_tab(): void
    {
        // Bootstrap Tab tìm container qua closest('.nav, .list-group, [role="tablist"]')
        // và im lặng không làm gì nếu không thấy — tab sẽ không đổi được.
        $this->actingAs($this->nguoiDung)
            ->get(route('ho-so.edit'))
            ->assertOk()
            ->assertSee('class="profile-nav" role="tablist"', false)
            ->assertSee('class="profile-tabbar" role="tablist"', false);
    }

    public function test_tong_quan_dem_don_hang_theo_trang_thai(): void
    {
        foreach (['Pending', 'Pending', 'Delivering', 'Completed'] as $status) {
            DonHang::create(['ma_nd' => $this->nguoiDung->ma_nd,
                'tong_tien' => 120000,
                'trang_thai' => $status,
                'dia_chi_giao_hang' => '1 Lê Văn Hiến, Đà Nẵng',
            ]);
        }

        $counts = $this->actingAs($this->nguoiDung)
            ->get(route('ho-so.edit'))
            ->assertOk()
            ->viewData('orderCounts');

        $this->assertSame(2, $counts['Pending']);
        $this->assertSame(1, $counts['Delivering']);
        $this->assertSame(1, $counts['Completed']);
        $this->assertSame(4, $counts->sum());
    }

    public function test_tong_quan_liet_ke_ba_don_hang_gan_nhat(): void
    {
        foreach (range(1, 5) as $i) {
            $donHang = DonHang::create(['ma_nd' => $this->nguoiDung->ma_nd,
                'tong_tien' => 10000 * $i,
                'trang_thai' => 'Completed',
                'dia_chi_giao_hang' => "{$i} Lê Văn Hiến, Đà Nẵng",
            ]);

            // created_at không nằm trong $fillable nên DonHang::create() bỏ qua nó và
            // cả 5 đơn dùng cùng một mốc thời gian.
            $donHang->forceFill(['created_at' => now()->subDays(10 - $i)])->save();
        }

        $recent = $this->actingAs($this->nguoiDung)
            ->get(route('ho-so.edit'))
            ->assertOk()
            ->viewData('recentOrders');

        $this->assertCount(3, $recent);
        // Mới nhất trước: đơn thứ 5 tạo gần nhất.
        $this->assertSame('5 Lê Văn Hiến, Đà Nẵng', $recent->first()->dia_chi_giao_hang);
    }

    public function test_tong_quan_chi_liet_ke_nhac_nho_dang_hoat_dong_cua_nguoi_dung_nay(): void
    {
        $danhMuc = DanhMuc::create(['ten' => 'Thuốc hô hấp']);
        $sanPham = SanPham::create(['ma_dm' => $danhMuc->ma_dm,
            'ten' => 'Thuốc ho Prospan',
            'gia' => 85000,
            'ngay_het_han' => now()->addYears(2),
            'so_luong_ton' => 10,
            'muc_canh_bao' => 4,
            'so_lo' => 'LC-prospan',
        ]);
        $other = NguoiDung::factory()->create();

        NhacUongThuoc::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'ma_sp' => $sanPham->ma_sp,
            'dosage_text' => '1 viên sau ăn',
            'times_per_day' => 2,
            'duration_days' => 5,
            'start_date' => now()->toDateString(),
            'next_remind_at' => now()->addHours(3),
            'status' => 'active',
        ]);
        NhacUongThuoc::create(['ma_nd' => $other->ma_nd,
            'ma_sp' => $sanPham->ma_sp,
            'dosage_text' => '2 viên sau ăn',
            'times_per_day' => 2,
            'duration_days' => 5,
            'start_date' => now()->toDateString(),
            'next_remind_at' => now()->addHours(1),
            'status' => 'active',
        ]);

        $reminders = $this->actingAs($this->nguoiDung)
            ->get(route('ho-so.edit'))
            ->assertOk()
            ->viewData('upcomingReminders');

        // Lịch nhắc của người khác không được lọt vào: MaKH đi trong URL email.
        $this->assertCount(1, $reminders);
        $this->assertSame((int) $this->nguoiDung->ma_nd, (int) $reminders->first()->ma_nd);
    }

    public function test_tien_do_suc_khoe_bi_an_khi_chua_dong_y(): void
    {
        HoSoSucKhoe::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'allergies' => ['penicillin'],
            'chronic_conditions' => ['tăng huyết áp'],
            'weight_kg' => 62,
            'consented_at' => null,
        ]);

        // Không đồng ý thì hồ sơ không được dùng cho bất kỳ mục đích nào, kể cả
        // hiển thị mức hoàn thiện — chỉ thấy lời mời khai báo.
        $this->actingAs($this->nguoiDung)
            ->get(route('ho-so.edit'))
            ->assertOk()
            ->assertSee(__('messages.profile.overview_health_empty'))
            ->assertDontSee(__('messages.profile.overview_health_complete', ['percent' => 75]));
    }

    public function test_tien_do_suc_khoe_hien_khi_da_dong_y(): void
    {
        HoSoSucKhoe::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'allergies' => ['penicillin'],
            'chronic_conditions' => ['tăng huyết áp'],
            'current_medications' => ['amlodipin'],
            'weight_kg' => 62,
            'consented_at' => now(),
        ]);

        $this->actingAs($this->nguoiDung)
            ->get(route('ho-so.edit'))
            ->assertOk()
            ->assertSee(__('messages.profile.overview_health_complete', ['percent' => 100]))
            ->assertDontSee(__('messages.profile.overview_health_empty'));
    }

    public function test_cong_tac_thong_bao_duoc_danh_dau_chua_kha_dung(): void
    {
        // Chúng chưa có chỗ lưu; để chúng bật/tắt được là nói dối người dùng.
        //
        // `disabled` phải nằm trong đúng thẻ input: chatbot widget cũng có chuỗi đó chục
        // lần, nên assertSee('disabled') trần vẫn xanh sau khi bỏ hẳn nó khỏi switch.
        $html = $this->actingAs($this->nguoiDung)
            ->get(route('ho-so.edit'))
            ->assertOk()
            ->assertSee(__('messages.profile.notifications_soon'))
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="orderNotif"[^>]*\sdisabled/s',
            $html,
            'Switch "thông báo đơn hàng" phải là disabled.'
        );
    }

    public function test_tong_quan_xem_truoc_ba_san_pham_yeu_thich(): void
    {
        $danhMuc = DanhMuc::create(['ten' => 'Thuốc tiêu hoá & gan mật']);

        foreach (range(1, 5) as $i) {
            $sanPham = SanPham::create(['ma_dm' => $danhMuc->ma_dm,
                'ten' => "Thuốc số {$i} điều trị viêm gan do virus, xơ gan",
                'gia' => 100000 + $i,
                'ngay_het_han' => now()->addYears(2),
                'so_luong_ton' => 10,
                'muc_canh_bao' => 4,
                'so_lo' => "LC-fav-{$i}",
            ]);

            YeuThich::create(['ma_nd' => $this->nguoiDung->ma_nd, 'ma_sp' => $sanPham->ma_sp]);
        }

        $response = $this->actingAs($this->nguoiDung)->get(route('ho-so.edit'))->assertOk();

        // Ba dòng, kèm tên + danh mục + giá — không còn là một thẻ trống với một nút.
        $this->assertSame(
            3,
            substr_count($response->getContent(), 'profile-overview-thumb'),
            'Thẻ yêu thích ở tổng quan phải hiện đúng 3 sản phẩm.'
        );

        $response->assertSee('Thuốc tiêu hoá &amp; gan mật', false)
            ->assertSee(__('messages.profile.overview_favorites_more', ['count' => 2]))
            ->assertSee(route('san-pham.show', 1), false);
    }

    public function test_muc_yeu_thich_tong_quan_dung_loi_keu_goi_khi_rong(): void
    {
        $this->actingAs($this->nguoiDung)
            ->get(route('ho-so.edit'))
            ->assertOk()
            ->assertSee(__('messages.profile.favorites_empty_desc'))
            ->assertSee(__('messages.profile.shop_now'))
            ->assertDontSee('profile-overview-thumb', false);
    }

    public function test_khach_vo_danh_duoc_chuyen_den_trang_dang_nhap(): void
    {
        $this->get(route('ho-so.edit'))->assertRedirect(route('login'));
    }
}
