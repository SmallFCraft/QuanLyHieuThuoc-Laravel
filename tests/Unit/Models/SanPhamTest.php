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
        $danhMuc = DanhMuc::create([
            'name' => 'Thuốc giảm đau',
            'name_folded' => 'thuoc giam dau',
            'code' => 'TGD',
        ]);

        $sanPham = SanPham::create([
            'product_code' => 'SP001',
            'MaDM' => $danhMuc->MaDM,
            'name' => 'Paracetamol 500mg',
            'name_folded' => 'paracetamol 500mg',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'batch_number' => 'BATCH-SP001',
            'stock_quantity' => 100,
        ]);

        $this->assertSame('SP001', $sanPham->product_code);
        $this->assertSame('Thuốc giảm đau', $sanPham->danhMuc->name);
        $this->assertSame($danhMuc->MaDM, $sanPham->danhMuc->MaDM);
    }
}
