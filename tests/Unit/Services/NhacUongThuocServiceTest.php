<?php

namespace Tests\Unit\Services;

use App\Models\DanhMuc;
use App\Models\NhacUongThuoc;
use App\Models\DonHang;
use App\Models\ChiTietDonHang;
use App\Models\SanPham;
use App\Models\NguoiDung;
use App\Services\NhacUongThuocService;
use Database\Seeders\TriThucYTeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NhacUongThuocServiceTest extends TestCase
{
    use RefreshDatabase;

    protected NhacUongThuocService $service;
    protected DanhMuc $danhMuc;
    protected NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        $this->danhMuc = DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt',
            'mo_ta' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(TriThucYTeSeeder::class);

        $this->service = app(NhacUongThuocService::class);

        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active',
            'ngay_sinh' => now()->subYears(34)->toDateString(),
        ]);
    }

    // --- parseTimesPerDay ---

    #[DataProvider('usageTextProvider')]
    public function test_so_lan_mot_ngay_duoc_phan_tich_tu_cach_dung_thuc_te(?string $usage, int $expected): void
    {
        $this->assertSame($expected, $this->service->phanTichSoLanMotNgay($usage));
    }

    public static function usageTextProvider(): array
    {
        return [
            'once daily' => ['Phòng ngừa đột quỵ, nhồi máu cơ tim. Uống 1 viên/ngày', 1],
            'range of times per day' => ['Giảm đau, chống viêm khớp. Uống 1 viên x 2-3 lần/ngày sau ăn', 3],
            'interval in hours' => ['Giảm đau, hạ sốt. Uống 1-2 viên mỗi 4-6 giờ, không quá 8 viên/ngày', 3],
            'fixed times per day' => ['Kháng sinh điều trị nhiễm khuẩn. Uống 1 viên x 3 lần/ngày', 3],
            'four times per day' => ['Kháng sinh nhóm cephalosporin thế hệ 1. Uống 1 viên x 4 lần/ngày', 4],
            'one to two times' => ['Giảm đau viêm khớp. Uống 1 viên x 1-2 lần/ngày', 2],
            'six to eight hours' => ['Giảm đau, chống viêm. Uống 1 viên mỗi 6-8 giờ sau ăn', 2],
            'liquid per day' => ['Điều trị táo bón. Uống 15-30ml/ngày', 1],
            'bedtime only' => ['Thuốc nhuận tràng. Uống 1-2 viên trước khi đi ngủ', 1],
            'as needed narrative' => ['Điều trị tiêu chảy cấp. Uống 2 viên lần đầu, sau đó 1 viên sau mỗi lần đi ngoài', 1],
            'null' => [null, 1],
            'empty' => ['', 1],
            'unparseable' => ['Dùng theo chỉ định của bác sĩ', 1],
        ];
    }

    // --- createFromOrder ---

    public function test_tao_tu_don_hang_mot_nhac_moi_dong(): void
    {
        $donHang = $this->orderWithProducts(3);

        $created = $this->service->taoTuDonHang($donHang);

        $this->assertSame(3, $created);
        $this->assertSame(3, NhacUongThuoc::where('ma_dh', $donHang->ma_dh)->count());
    }

    public function test_tao_tu_don_hang_chay_lai_khong_nhan_ban(): void
    {
        $donHang = $this->orderWithProducts(2);

        $this->service->taoTuDonHang($donHang);
        $this->service->taoTuDonHang($donHang);

        $this->assertSame(2, NhacUongThuoc::where('ma_dh', $donHang->ma_dh)->count());
    }

    public function test_nhac_dau_tien_roi_vao_gio_thuc(): void
    {
        // Pinned to the middle of the night: the first slot must still land inside
        // 08:00–20:00 rather than waking the customer up.
        $this->travelTo(now()->startOfDay()->setTime(3, 0));

        $donHang = $this->orderWithProducts(1);

        $this->service->taoTuDonHang($donHang);
        $nhacUong = NhacUongThuoc::where('ma_dh', $donHang->ma_dh)->firstOrFail();

        $this->assertNotNull($nhacUong->next_remind_at);
        $this->assertGreaterThanOrEqual(8, $nhacUong->next_remind_at->hour);
        $this->assertLessThan(20, $nhacUong->next_remind_at->hour);
    }

    public function test_nhac_copy_cach_dung_va_tan_suat_tu_san_pham(): void
    {
        $sanPham = $this->product('Uống 1 viên x 3 lần/ngày');
        $donHang = $this->order();
        ChiTietDonHang::create(['ma_dh' => $donHang->ma_dh,
            'ma_sp' => $sanPham->ma_sp,
            'so_luong' => 9,
            'don_gia' => 10000,
            'so_lo' => 'B-1',
        ]);

        $this->service->taoTuDonHang($donHang->fresh());
        $nhacUong = NhacUongThuoc::where('ma_sp', $sanPham->ma_sp)->firstOrFail();

        $this->assertSame('Uống 1 viên x 3 lần/ngày', $nhacUong->dosage_text);
        $this->assertSame(3, $nhacUong->times_per_day);
        $this->assertSame(3, $nhacUong->duration_days);
    }

    // --- advanceSchedule ---

    public function test_lich_hoan_thanh_khi_khoa_duoc_da_het(): void
    {
        $nhacUong = $this->reminder([
            'times_per_day' => 1,
            'duration_days' => 1,
            'start_date' => now()->subDays(5)->toDateString(),
            'next_remind_at' => now()->subDays(1)->setTime(10, 0),
        ]);

        $nhacUong->advanceSchedule();

        $this->assertSame('completed', $nhacUong->fresh()->status);
        $this->assertNull($nhacUong->fresh()->next_remind_at);
    }

    public function test_lich_gio_thuc_khi_qua_dem(): void
    {
        $nhacUong = $this->reminder([
            'times_per_day' => 1,
            'duration_days' => 30,
            'start_date' => now()->toDateString(),
            'next_remind_at' => now()->setTime(19, 0),
        ]);

        $nhacUong->advanceSchedule();
        $next = $nhacUong->fresh()->next_remind_at;

        $this->assertNotNull($next);
        $this->assertGreaterThanOrEqual(8, $next->hour);
        $this->assertLessThan(20, $next->hour);
    }

    // --- dispatchDue ---

    public function test_gui_dung_han_chi_cham_nhac_active_va_dung_han(): void
    {
        Mail::fake();

        $due = $this->reminder(['next_remind_at' => now()->subMinutes(5)]);
        $future = $this->reminder(['next_remind_at' => now()->addHours(3)]);
        $cancelled = $this->reminder([
            'next_remind_at' => now()->subMinutes(5),
            'status' => 'cancelled',
        ]);

        $sent = $this->service->guiDungHan();

        $this->assertSame(1, $sent);
        Mail::assertQueued(\App\Mail\NhacUongThuocMail::class, 1);
        $this->assertNotSame($due->next_remind_at, $due->fresh()->next_remind_at);
        $this->assertEquals(
            $future->next_remind_at->timestamp,
            $future->fresh()->next_remind_at->timestamp
        );
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    public function test_gui_dung_han_khong_gui_gi_khi_khong_co_nhac_dung_han(): void
    {
        Mail::fake();
        $this->reminder(['next_remind_at' => now()->addDay()]);

        $this->assertSame(0, $this->service->guiDungHan());
        Mail::assertNothingQueued();
    }

    // --- cancelForUser ---

    public function test_huy_theo_nguoi_dung_chi_huy_nhac_cua_nguoi_do(): void
    {
        $other = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
        $sanPham = $this->product('Uống 1 viên/ngày');

        $mine = $this->reminder(['ma_sp' => $sanPham->ma_sp]);
        $theirs = $this->reminder(['ma_sp' => $sanPham->ma_sp, 'ma_nd' => $other->ma_nd]);

        $this->service->huyTheoNguoiDung((int) $this->nguoiDung->ma_nd, (int) $sanPham->ma_sp);

        $this->assertSame('cancelled', $mine->fresh()->status);
        $this->assertSame('active', $theirs->fresh()->status);
    }

    // --- Console command ---

    public function test_lenh_console_gui_nhac_dung_han(): void
    {
        Mail::fake();
        $this->reminder(['next_remind_at' => now()->subMinutes(5)]);

        $this->artisan('reminders:dispatch')->assertSuccessful();

        Mail::assertQueued(\App\Mail\NhacUongThuocMail::class, 1);
    }

    // --- Ownership through the HTTP layer ---

    public function test_khach_hang_chi_thay_nhac_active_cua_minh(): void
    {
        $other = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
        $mineProduct = $this->product('Uống 1 viên/ngày');
        $theirsProduct = $this->product('Uống 1 viên x 2 lần/ngày');

        $this->reminder(['ma_sp' => $mineProduct->ma_sp]);
        $this->reminder(['ma_sp' => $theirsProduct->ma_sp, 'ma_nd' => $other->ma_nd]);

        // Neo vào aria-label của nút huỷ, không phải tên sản phẩm trần: panel tìm kiếm
        // nhúng sản phẩm xem nhiều vào mọi trang storefront, nên quét cả trang thì tên
        // nào cũng có thể xuất hiện ngoài bảng nhắc thuốc.
        $cancelLabel = fn (SanPham $sanPham) => __('messages.reminder.btn_cancel_aria', ['name' => $sanPham->ten]);

        $this->actingAs($this->nguoiDung)
            ->get(route('nhac-uong.index'))
            ->assertOk()
            ->assertSee($cancelLabel($mineProduct))
            ->assertDontSee($cancelLabel($theirsProduct));
    }

    public function test_khach_hang_khong_huy_duoc_nhac_cua_nguoi_khac(): void
    {
        $other = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
        $theirs = $this->reminder(['ma_nd' => $other->ma_nd]);

        $this->actingAs($this->nguoiDung)
            ->post(route('nhac-uong.huy', $theirs->ma_nn))
            ->assertRedirect(route('nhac-uong.index'));

        $this->assertSame('active', $theirs->fresh()->status);
    }

    public function test_khach_hang_huy_duoc_nhac_cua_minh(): void
    {
        $mine = $this->reminder();

        $this->actingAs($this->nguoiDung)
            ->post(route('nhac-uong.huy', $mine->ma_nn))
            ->assertRedirect(route('nhac-uong.index'));

        $this->assertSame('cancelled', $mine->fresh()->status);
    }

    // --- helpers ---

    private function product(string $usage): SanPham
    {
        return SanPham::factory()->create(['ma_dm' => $this->danhMuc->ma_dm,
            'cach_dung' => $usage,
        ]);
    }

    private function order(?NguoiDung $nguoiDung = null): DonHang
    {
        return DonHang::create(['ma_nd' => ($nguoiDung ?? $this->nguoiDung)->ma_nd,
            'tong_tien' => 100000,
            'trang_thai' => 'Completed',
            'dia_chi_giao_hang' => '12 Nguyễn Huệ, Quận 1',
            'sdt_giao_hang' => '0901234567',
        ]);
    }

    private function orderWithProducts(int $lines): DonHang
    {
        $donHang = $this->order();
        $usages = [
            'Uống 1 viên/ngày',
            'Uống 1 viên x 2-3 lần/ngày sau ăn',
            'Uống 1-2 viên mỗi 4-6 giờ, không quá 8 viên/ngày',
        ];

        for ($i = 0; $i < $lines; $i++) {
            $sanPham = $this->product($usages[$i % count($usages)]);
            ChiTietDonHang::create(['ma_dh' => $donHang->ma_dh,
                'ma_sp' => $sanPham->ma_sp,
                'so_luong' => 6,
                'don_gia' => 10000,
                'so_lo' => 'B-' . $i,
            ]);
        }

        return $donHang->fresh();
    }

    private function reminder(array $attributes = []): NhacUongThuoc
    {
        return NhacUongThuoc::create(array_merge([
            'ma_nd' => $this->nguoiDung->ma_nd,
            'ma_dh' => null,
            'ma_sp' => $this->product('Uống 1 viên x 2 lần/ngày')->ma_sp,
            'dosage_text' => 'Uống 1 viên x 2 lần/ngày',
            'times_per_day' => 2,
            'duration_days' => 5,
            'start_date' => now()->toDateString(),
            'next_remind_at' => now(),
            'status' => 'active',
        ], $attributes));
    }
}
