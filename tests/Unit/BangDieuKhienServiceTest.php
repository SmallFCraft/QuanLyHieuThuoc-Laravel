<?php

namespace Tests\Unit;

use App\Models\DanhMuc;
use App\Models\DonHang;
use App\Models\SanPham;
use App\Models\NguoiDung;
use App\Services\BangDieuKhienService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BangDieuKhienServiceTest extends TestCase
{
    use RefreshDatabase;

    private BangDieuKhienService $bangDieuKhienService;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00'));
        $this->bangDieuKhienService = app(BangDieuKhienService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_bieu_do_doanh_thu_ngay_gom_don_tao_sau_trong_ngay(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $this->createOrder($nguoiDung, 'Completed', 125000, '2026-07-06 16:30:00');
        $this->createOrder($nguoiDung, 'Cancelled', 999000, '2026-07-06 17:00:00');

        $chart = $this->bangDieuKhienService->layBieuDoDoanhThuNgay(1);

        $this->assertSame(['06/07'], $chart['labels']);
        $this->assertEquals(125000.0, (float) $chart['data'][0]);
    }

    public function test_doanh_thu_thang_chi_dem_don_delivering_va_completed(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        foreach ([
            ['Completed', 100000, '2026-07-01 09:00:00'],
            ['Delivering', 50000, '2026-07-02 09:00:00'],
            ['Pending', 700000, '2026-07-03 09:00:00'],
            ['Cancelled', 900000, '2026-07-04 09:00:00'],
            ['Completed', 300000, '2026-06-30 09:00:00'],
        ] as [$status, $amount, $createdAt]) {
            $this->createOrder($nguoiDung, $status, $amount, $createdAt);
        }

        $this->assertEquals(150000.0, $this->bangDieuKhienService->layDoanhThuThang());
    }

    public function test_san_pham_ban_chay_loai_bo_don_pending_va_cancelled(): void
    {
        $nguoiDung = NguoiDung::factory()->create();
        $danhMuc = DanhMuc::create(['ten' => 'Dashboard Test']);
        $sanPham = SanPham::create(['ma_dm' => $danhMuc->ma_dm,
            'ten' => 'Vitamin Dashboard',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'DASHBOARD-001',
        ]);

        $completedOrder = $this->createOrder($nguoiDung, 'Completed', 200000, '2026-07-06 10:00:00');
        $pendingOrder = $this->createOrder($nguoiDung, 'Pending', 5000000, '2026-07-06 10:00:00');

        DB::table('chi_tiet_don_hangs')->insert([
            [
                'ma_dh' => $completedOrder->ma_dh,
                'ma_sp' => $sanPham->ma_sp,
                'so_luong' => 4,
                'don_gia' => 50000,
                'so_lo' => 'DASHBOARD-001',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'ma_dh' => $pendingOrder->ma_dh,
                'ma_sp' => $sanPham->ma_sp,
                'so_luong' => 100,
                'don_gia' => 50000,
                'so_lo' => 'DASHBOARD-001',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $chart = $this->bangDieuKhienService->laySanPhamBanChay(10);

        $this->assertSame(['Vitamin Dashboard'], $chart['labels']);
        $this->assertSame([4], array_map('intval', $chart['data']));
    }

    private function createOrder(NguoiDung $nguoiDung, string $status, int $amount, string $createdAt): DonHang
    {
        $timestamp = Carbon::parse($createdAt);
        $id = DB::table('don_hangs')->insertGetId([
            'ma_nd' => $nguoiDung->ma_nd,
            'tong_tien' => $amount,
            'trang_thai' => $status,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return DonHang::findOrFail($id);
    }
}
