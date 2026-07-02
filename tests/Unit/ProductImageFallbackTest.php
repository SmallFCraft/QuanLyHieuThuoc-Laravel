<?php

namespace Tests\Unit;

use App\Models\Product;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageFallbackTest extends TestCase
{
    public function test_image_url_supports_legacy_and_prefixed_local_paths(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/medicine.jpg', 'image');

        $legacyProduct = new Product(['image' => 'medicine.jpg']);
        $prefixedProduct = new Product(['image' => 'products/medicine.jpg']);

        $this->assertStringEndsWith('/storage/products/medicine.jpg', $legacyProduct->image_url);
        $this->assertSame($legacyProduct->image_url, $prefixedProduct->image_url);
    }

    public function test_image_url_returns_null_for_a_missing_local_file(): void
    {
        Storage::fake('public');

        $product = new Product(['image' => 'missing.jpg']);

        $this->assertNull($product->image_url);
    }

    public function test_image_url_preserves_external_urls(): void
    {
        $product = new Product(['image' => 'https://example.com/medicine.jpg']);

        $this->assertSame('https://example.com/medicine.jpg', $product->image_url);
    }

    public function test_product_image_component_contains_accessible_fallback_behavior(): void
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
