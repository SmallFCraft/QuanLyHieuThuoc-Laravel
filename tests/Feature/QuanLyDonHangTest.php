<?php

namespace Tests\Feature;

use App\Models\NguoiDung;
use App\Models\SanPham;
use App\Models\DanhMuc;
use App\Models\DonHang;
use App\Models\ChiTietDonHang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuanLyDonHangTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $nguoiDung;
    protected $danhMuc;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->admin = NguoiDung::factory()->create(['vai_tro' => 'Administrator',
            'trang_thai' => 'active'
        ]);
        
        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active'
        ]);
        
        $this->danhMuc = DanhMuc::create(['ten' => 'Test Category',
            'mo_ta' => 'Test Description'
        ]);
    }

    public function test_quan_tri_vien_xem_duoc_danh_sach_don_hang(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.don-hang.index'));

        $response->assertStatus(200);
        $response->assertViewIs('admin.orders.index');
    }

    public function test_nguoi_khong_phai_quan_tri_khong_truy_cap_duoc_quan_ly_don_hang(): void
    {
        $response = $this->actingAs($this->nguoiDung)->get(route('admin.don-hang.index'));

        $response->assertStatus(403);
    }

    public function test_quan_tri_vien_cap_nhat_don_tu_cho_xu_ly_sang_dang_giao(): void
    {
        $donHang = DonHang::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'tong_tien' => 20000,
            'trang_thai' => 'Pending',
            'dia_chi_giao_hang' => '123 Test Street',
            'sdt_giao_hang' => '0901234567'
        ]);

        $response = $this->actingAs($this->admin)->patch(
            route('admin.don-hang.cap-nhat-trang-thai', $donHang->ma_dh),
            ['status' => 'Delivering']
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $donHang->refresh();
        $this->assertEquals('Delivering', $donHang->trang_thai);
    }

    public function test_quan_tri_vien_cap_nhat_don_tu_dang_giao_sang_hoan_thanh(): void
    {
        $donHang = DonHang::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'tong_tien' => 20000,
            'trang_thai' => 'Delivering',
            'dia_chi_giao_hang' => '123 Test Street',
            'sdt_giao_hang' => '0901234567'
        ]);

        $response = $this->actingAs($this->admin)->patch(
            route('admin.don-hang.cap-nhat-trang-thai', $donHang->ma_dh),
            ['status' => 'Completed']
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $donHang->refresh();
        $this->assertEquals('Completed', $donHang->trang_thai);
    }

    public function test_khong_cap_nhat_duoc_trang_thai_don_da_hoan_thanh(): void
    {
        $donHang = DonHang::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'tong_tien' => 20000,
            'trang_thai' => 'Completed',
            'dia_chi_giao_hang' => '123 Test Street',
            'sdt_giao_hang' => '0901234567'
        ]);

        $response = $this->actingAs($this->admin)->patch(
            route('admin.don-hang.cap-nhat-trang-thai', $donHang->ma_dh),
            ['status' => 'Pending']
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $donHang->refresh();
        $this->assertEquals('Completed', $donHang->trang_thai);
    }

    public function test_quan_tri_vien_huy_duoc_don_cho_xu_ly_va_hoan_ton_kho(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Product',
            'gia' => 10000,
            'ngay_het_han' => now()->addMonths(6),
            'so_luong_ton' => 5,
            'muc_canh_bao' => 5,
            'so_lo' => 'BATCH-001'
        ]);

        $donHang = DonHang::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'tong_tien' => 20000,
            'trang_thai' => 'Pending',
            'dia_chi_giao_hang' => '123 Test Street',
            'sdt_giao_hang' => '0901234567'
        ]);

        ChiTietDonHang::create(['ma_dh' => $donHang->ma_dh,
            'ma_sp' => $sanPham->ma_sp,
            'so_luong' => 2,
            'don_gia' => 10000,
            'so_lo' => 'BATCH-001'
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('admin.don-hang.huy', $donHang->ma_dh)
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Verify order status changed to Cancelled
        $donHang->refresh();
        $this->assertEquals('Cancelled', $donHang->trang_thai);

        // Verify inventory was restored
        $sanPham->refresh();
        $this->assertEquals(7, $sanPham->so_luong_ton);
    }

    public function test_khong_huy_duoc_don_da_hoan_thanh(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Product',
            'gia' => 10000,
            'ngay_het_han' => now()->addMonths(6),
            'so_luong_ton' => 5,
            'muc_canh_bao' => 5,
            'so_lo' => 'BATCH-002'
        ]);

        $donHang = DonHang::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'tong_tien' => 20000,
            'trang_thai' => 'Completed',
            'dia_chi_giao_hang' => '123 Test Street',
            'sdt_giao_hang' => '0901234567'
        ]);

        ChiTietDonHang::create(['ma_dh' => $donHang->ma_dh,
            'ma_sp' => $sanPham->ma_sp,
            'so_luong' => 2,
            'don_gia' => 10000,
            'so_lo' => 'BATCH-002'
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('admin.don-hang.huy', $donHang->ma_dh)
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');

        // Verify order status did not change
        $donHang->refresh();
        $this->assertEquals('Completed', $donHang->trang_thai);

        // Verify inventory was not changed
        $sanPham->refresh();
        $this->assertEquals(5, $sanPham->so_luong_ton);
    }
}
