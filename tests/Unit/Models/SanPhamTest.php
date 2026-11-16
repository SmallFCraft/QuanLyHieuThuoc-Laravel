<?php

namespace Tests\Unit\Models;

use App\Models\SanPham;
use App\Models\DanhMuc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SanPhamTest extends TestCase
{
    use RefreshDatabase;

    public function test_tao_san_pham_va_quan_he_danh_muc(): void
    {
        $danhMuc = DanhMuc::create(['ten' => 'Thuốc giảm đau',
            'ten_khong_dau' => 'thuoc giam dau',
            'ma_danh_muc' => 'TGD',
        ]);

        $sanPham = SanPham::create(['ma_san_pham' => 'SP001',
            'ma_dm' => $danhMuc->ma_dm,
            'ten' => 'Paracetamol 500mg',
            'ten_khong_dau' => 'paracetamol 500mg',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_lo' => 'BATCH-SP001',
            'so_luong_ton' => 100,
        ]);

        $this->assertSame('SP001', $sanPham->ma_san_pham);
        $this->assertSame('Thuốc giảm đau', $sanPham->danhMuc->ten);
        $this->assertSame($danhMuc->ma_dm, $sanPham->danhMuc->ma_dm);
    }
}
