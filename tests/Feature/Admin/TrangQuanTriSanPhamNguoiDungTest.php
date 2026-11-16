<?php

namespace Tests\Feature\Admin;

use App\Models\DanhMuc;
use App\Models\NguoiDung;
use App\Models\SanPham;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrangQuanTriSanPhamNguoiDungTest extends TestCase
{
    use RefreshDatabase;

    private NguoiDung $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = NguoiDung::factory()->create(['vai_tro' => 'Administrator']);
        $danhMuc = DanhMuc::create(['ten' => 'Thuốc giảm đau, hạ sốt']);
        SanPham::create(['ma_dm' => $danhMuc->ma_dm,
            'ten' => 'Viên nén Paracetamol Stada 500mg',
            'gia' => 50000,
            'ngay_het_han' => now()->addYears(2),
            'so_luong_ton' => 30,
            'muc_canh_bao' => 10,
            'so_lo' => 'LC-PARA-1',
        ]);
    }

    public function test_trang_san_pham_quan_tri_hien_danh_sach_khong_loi_500(): void
    {
        // Danh sách render bằng DataTables AJAX, tên sản phẩm nằm ở /data chứ không
        // trong HTML trang — assert container bảng là đủ để bắt 500 compact().
        $this->actingAs($this->admin)
            ->get(route('admin.san-pham.index'))
            ->assertOk()
            ->assertSee('productsTable', false);
    }

    public function test_trang_tao_san_pham_khong_loi_500(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.san-pham.create'))
            ->assertOk()
            ->assertSee('Thuốc giảm đau', false);
    }

    public function test_trang_chi_tiet_san_pham_khong_loi_500(): void
    {
        $sanPham = SanPham::firstWhere('so_lo', 'LC-PARA-1');

        $this->actingAs($this->admin)
            ->get(route('admin.san-pham.show', $sanPham->ma_sp))
            ->assertOk()
            ->assertSee('Paracetamol', false);
    }

    public function test_trang_sua_san_pham_khong_loi_500(): void
    {
        $sanPham = SanPham::firstWhere('so_lo', 'LC-PARA-1');

        $this->actingAs($this->admin)
            ->get(route('admin.san-pham.edit', $sanPham->ma_sp))
            ->assertOk()
            ->assertSee('Paracetamol', false);
    }

    public function test_trang_nguoi_dung_quan_tri_hien_danh_sach_khong_loi_500(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.nguoi-dung.index'))
            ->assertOk()
            ->assertSee($this->admin->email, false);
    }
}
