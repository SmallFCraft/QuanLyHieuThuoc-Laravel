<?php

namespace Tests\Unit\Support;

use App\Models\DanhMuc;
use App\Support\TaoMaSanPham;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaoMaSanPhamTest extends TestCase
{
    use RefreshDatabase;

    public function test_chuan_hoa_ma_danh_muc(): void
    {
        $this->assertSame('THUOCDIUNG', TaoMaSanPham::chuanHoaMaDanhMuc(' Thuốc-dị ứng 123!'));
        $this->assertNull(TaoMaSanPham::chuanHoaMaDanhMuc(' '));
    }

    public function test_tao_ma_danh_muc_duy_nhat(): void
    {
        DanhMuc::create([
            'ten' => 'Thuốc giảm đau',
            'ma_danh_muc' => 'TGD',
        ]);

        $this->assertSame('TGD2', TaoMaSanPham::maDanhMucDuyNhat('Thuốc giảm đau'));
    }

    public function test_tao_ma_san_pham_theo_danh_muc(): void
    {
        $danhMuc = DanhMuc::create([
            'ten' => 'Thuốc giảm đau',
            'ma_danh_muc' => 'TGD',
        ]);

        $this->assertMatchesRegularExpression(
            '/^TGD\d{5}$/',
            TaoMaSanPham::maSanPhamTheoDanhMuc($danhMuc),
        );
    }
}
