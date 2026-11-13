<?php

namespace Tests\Unit;

use App\Models\SanPham;
use App\Models\DanhMuc;
use App\Models\NguoiDung;
use App\Models\NhatKyKiemToan;
use App\Services\TonKhoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuanLyTonKhoTest extends TestCase
{
    use RefreshDatabase;

    protected $danhMuc;
    protected $admin;
    protected $inventoryService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->danhMuc = DanhMuc::create(['ten' => 'Test Category',
            'mo_ta' => 'Test Description'
        ]);

        $this->admin = NguoiDung::factory()->create(['vai_tro' => 'Administrator',
            'trang_thai' => 'active'
        ]);

        $this->tonKhoService = app(TonKhoService::class);
    }

    public function test_canh_bao_sap_het_khi_so_luong_duoi_muc_canh_bao(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Low Stock Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 5,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-001'
        ]);

        $lowStockProducts = $this->tonKhoService->laySanPhamSapHet();

        $this->assertTrue($lowStockProducts->contains($sanPham));
    }

    public function test_fefo_xep_theo_ngay_het_han(): void
    {
        $product1 = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Medicine A',
            'gia' => 50000,
            'ngay_het_han' => now()->addMonths(3),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-002'
        ]);

        $product2 = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Medicine B',
            'gia' => 50000,
            'ngay_het_han' => now()->addMonths(1),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-003'
        ]);

        $product3 = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Medicine C',
            'gia' => 50000,
            'ngay_het_han' => now()->addMonths(6),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-004'
        ]);

        $sortedProducts = SanPham::orderBy('ngay_het_han', 'asc')->get();

        $this->assertEquals($product2->ma_sp, $sortedProducts->first()->ma_sp);
        $this->assertEquals($product3->ma_sp, $sortedProducts->last()->ma_sp);
    }

    public function test_dieu_chinh_ton_kho_co_ly_do(): void
    {
        $this->actingAs($this->admin);

        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-005'
        ]);

        $result = $this->tonKhoService->dieuChinhTonKho(
            $sanPham->ma_sp,
            20,
            'Received new shipment',
            $this->admin->ma_nd
        );

        $this->assertTrue($result);

        $sanPham->refresh();
        $this->assertEquals(120, $sanPham->so_luong_ton);

        // Verify audit log
        $this->assertDatabaseHas('nhat_ky_kiem_toans', [
            'model_type' => SanPham::class,
            'MaDoiTuong' => $sanPham->ma_sp,
            'action' => 'inventory_adjustment',
            'ma_nd' => $this->admin->ma_nd
        ]);
    }

    public function test_chan_so_luong_ton_am(): void
    {
        $this->actingAs($this->admin);

        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 10,
            'muc_canh_bao' => 5,
            'so_lo' => 'BATCH-006'
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Số lượng tồn kho không thể âm');

        $this->tonKhoService->dieuChinhTonKho(
            $sanPham->ma_sp,
            -15,
            'Invalid adjustment',
            $this->admin->ma_nd
        );
    }

    public function test_cap_nhat_ton_kho_dong_thoi_co_khoa(): void
    {
        // Không tạo lô (bỏ qua observer): test thao tác trực tiếp cột san_phams.so_luong_ton
        $sanPham = SanPham::withoutEvents(function () {
            return SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
                'ten' => 'Test Medicine',
                'gia' => 50000,
                'ngay_het_han' => now()->addYear(),
                'so_luong_ton' => 100,
                'muc_canh_bao' => 10,
                'so_lo' => 'BATCH-007',
            ]);
        });

        // Simulate concurrent updates
        DB::transaction(function () use ($sanPham) {
            $lockedProduct = SanPham::lockForUpdate()->find($sanPham->ma_sp);
            $lockedProduct->so_luong_ton -= 10;
            $lockedProduct->save();
        });

        DB::transaction(function () use ($sanPham) {
            $lockedProduct = SanPham::lockForUpdate()->find($sanPham->ma_sp);
            $lockedProduct->so_luong_ton -= 5;
            $lockedProduct->save();
        });

        $sanPham->refresh();
        $this->assertEquals(85, $sanPham->so_luong_ton);
    }

    public function test_kiem_tra_het_han_tra_san_pham_sap_het_han(): void
    {
        $expiringProduct = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Expiring Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->addDays(15),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-008'
        ]);

        $validProduct = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Valid Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-009'
        ]);

        $expiringProducts = $this->tonKhoService->kiemTraHetHan(30);

        $this->assertTrue($expiringProducts->contains($expiringProduct));
        $this->assertFalse($expiringProducts->contains($validProduct));
    }

    public function test_dieu_chinh_ton_kho_tao_nhat_ky_kiem_toan_co_chi_tiet(): void
    {
        $this->actingAs($this->admin);

        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-010'
        ]);

        $this->tonKhoService->dieuChinhTonKho(
            $sanPham->ma_sp,
            150,
            'Stock replenishment',
            $this->admin->ma_nd
        );

        $nhatKy = NhatKyKiemToan::where('MaDoiTuong', $sanPham->ma_sp)
            ->where('action', 'inventory_adjustment')
            ->first();

        $this->assertNotNull($nhatKy);
        $this->assertEquals($this->admin->ma_nd, $nhatKy->ma_nd);
        $this->assertNotNull($nhatKy->old_values);
        $this->assertNotNull($nhatKy->new_values);
    }
}
