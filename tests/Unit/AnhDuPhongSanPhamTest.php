<?php

namespace Tests\Unit;

use App\Models\SanPham;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnhDuPhongSanPhamTest extends TestCase
{
    public function test_image_url_nhan_ca_du_lieu_du_phong_dia_phuong_cu_va_co_tien_to(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/medicine.jpg', 'image');

        $legacyProduct = new SanPham(['hinh_anh' => 'medicine.jpg']);
        $prefixedProduct = new SanPham(['hinh_anh' => 'products/medicine.jpg']);

        $this->assertStringEndsWith('/storage/products/medicine.jpg', $legacyProduct->image_url);
        $this->assertSame($legacyProduct->image_url, $prefixedProduct->image_url);
    }

    public function test_image_url_tra_null_khi_file_dia_phuong_khong_ton_tai(): void
    {
        Storage::fake('public');

        $sanPham = new SanPham(['hinh_anh' => 'missing.jpg']);

        $this->assertNull($sanPham->image_url);
    }

    public function test_image_url_giu_nguyen_url_ngoai(): void
    {
        $sanPham = new SanPham(['hinh_anh' => 'https://example.com/medicine.jpg']);

        $this->assertSame('https://example.com/medicine.jpg', $sanPham->image_url);
    }

    public function test_component_hinh_san_pham_co_hanh_vi_du_phong_dang_duoc(): void
    {
        $html = Blade::render(
            '<x-product-image src="https://example.com/missing.jpg" alt="Paracetamol" class="product-photo" />'
        );

        $this->assertStringContainsString('bi-capsule', $html);
        $this->assertStringContainsString('Không có hình ảnh cho Paracetamol', $html);
        $this->assertStringContainsString("this.nextElementSibling.classList.remove('d-none')", $html);
        $this->assertStringContainsString('d-flex', $html);
        $this->assertStringContainsString('product-photo', $html);
        $this->assertStringContainsString('position-absolute top-0 start-0', $html);
    }
}
