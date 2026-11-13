<?php

namespace Tests\Unit\Services;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Services\AiProviderService;
use App\Services\NhatKyKiemToanService;
use App\Services\NhanDienHinhAnhService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NhanDienHinhAnhServiceTest extends TestCase
{
    use RefreshDatabase;

    protected NhanDienHinhAnhService $service;
    protected DanhMuc $danhMuc;

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new NhanDienHinhAnhService(
            $this->createMock(NhatKyKiemToanService::class),
            $this->createMock(AiProviderService::class)
        );

        $this->danhMuc = DanhMuc::factory()->create(['ten' => 'Vitamin và khoáng chất']);
    }

    protected function createProduct(string $name, string $ingredients = '', string $usage = ''): SanPham
    {
        return SanPham::factory()->create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => $name,
            'thanh_phan' => $ingredients,
            'cach_dung' => $usage,
            'so_luong_ton' => 50,
            'ngay_het_han' => now()->addYear(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function parsedVitaminA(): array
    {
        return [
            'name' => 'Vitamin A 5000 IU',
            'dosage' => '5000 IU',
            'quantity' => 1,
            'active_ingredients' => ['Retinol', 'Vitamin A'],
            'generic_names' => ['Retinol'],
            'category' => 'Vitamin và khoáng chất',
            'uses' => ['Bổ sung vitamin A', 'Hỗ trợ thị lực'],
            'health_goals' => ['immunity', 'eye health'],
            'visible_text' => 'Vitamin A 5000 IU Mekophar hộp 10 viên nang cứng',
            'instructions' => 'Uống 1 viên mỗi ngày',
        ];
    }

    public function test_anh_vitamin_a_khong_khop_vo_thuc_pham_bo_sung_khong_lien_quan(): void
    {
        $this->createProduct('Zinc 50mg', 'Zinc sulfate 50mg', 'Bổ sung kẽm, tăng cường miễn dịch. Uống 1 viên/ngày');
        $this->createProduct('Iron + Folic Acid', 'Sắt fumarate, Acid folic', 'Bổ sung sắt. Uống 1 viên mỗi ngày');

        $matched = $this->service->khopSanPham([$this->parsedVitaminA()]);

        $this->assertCount(1, $matched);
        $this->assertFalse($matched[0]['matched']);
        $this->assertNull($matched[0]['product']);
        $this->assertSame('Vitamin A 5000 IU', $matched[0]['parsed_name']);
    }

    public function test_anh_vitamin_a_khong_khop_vo_chu_vitamin_khac(): void
    {
        $this->createProduct('Vitamin C 1000mg', 'Acid ascorbic 1000mg', 'Bổ sung vitamin C, tăng sức đề kháng');
        $this->createProduct('Vitamin E 400IU', 'Alpha tocopherol 400IU', 'Bổ sung vitamin E');

        $matched = $this->service->khopSanPham([$this->parsedVitaminA()]);

        $this->assertFalse($matched[0]['matched']);
    }

    public function test_anh_khong_khop_de_xuat_san_pham_cung_nhom(): void
    {
        $this->createProduct('Vitamin C 1000mg', 'Acid ascorbic 1000mg', 'Bổ sung vitamin C, tăng sức đề kháng');
        $this->createProduct('Multivitamin', 'Vitamin nhóm B, Vitamin C', 'Bổ sung vitamin tổng hợp mỗi ngày');
        $this->createProduct('Zinc 50mg', 'Zinc sulfate 50mg', 'Bổ sung kẽm, tăng cường miễn dịch');

        $matched = $this->service->khopSanPham([$this->parsedVitaminA()]);
        $suggestionNames = array_column($matched[0]['suggestions'] ?? [], 'name');

        $this->assertFalse($matched[0]['matched']);
        $this->assertNotEmpty($suggestionNames);
        $this->assertNotContains('Zinc 50mg', $suggestionNames);
    }

    public function test_ten_thuong_mai_tuong_duong_van_khop_san_pham_trong_catalog(): void
    {
        $sanPham = $this->createProduct('Vitamin C 1000mg', 'Acid ascorbic 1000mg', 'Bổ sung vitamin C, tăng sức đề kháng');
        $this->createProduct('Zinc 50mg', 'Zinc sulfate 50mg', 'Bổ sung kẽm');

        $matched = $this->service->khopSanPham([[
            'name' => 'Blackmores Bio C',
            'quantity' => 31,
            'dosage' => '1000mg',
            'instructions' => 'Reduces the severity and duration of common cold symptoms',
        ]]);

        $this->assertTrue($matched[0]['matched']);
        $this->assertSame($sanPham->ma_sp, $matched[0]['product']['id']);
    }

    public function test_ten_san_pham_chinh_xac_duoc_khop(): void
    {
        $sanPham = $this->createProduct('Zinc 50mg', 'Zinc sulfate 50mg', 'Bổ sung kẽm');

        $matched = $this->service->khopSanPham([[
            'name' => 'Zinc 50mg',
            'dosage' => '50mg',
            'active_ingredients' => ['Zinc sulfate'],
        ]]);

        $this->assertTrue($matched[0]['matched']);
        $this->assertSame($sanPham->ma_sp, $matched[0]['product']['id']);
        $this->assertGreaterThanOrEqual(0.9, $matched[0]['confidence']);
    }

    public function test_ten_khong_dau_khop_vo_ten_catalog_co_dau(): void
    {
        $sanPham = $this->createProduct('Nước muối sinh lý 0.9%', 'Natri clorid 0.9%', 'Rửa mũi, vệ sinh mắt');

        $matched = $this->service->khopSanPham([[
            'name' => 'Nuoc muoi sinh ly',
            'active_ingredients' => ['Natri clorid'],
        ]]);

        $this->assertTrue($matched[0]['matched']);
        $this->assertSame($sanPham->ma_sp, $matched[0]['product']['id']);
    }

    public function test_lieu_duoc_phan_biet_hai_nong_do_cung_mot_thuoc(): void
    {
        $this->createProduct('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau, hạ sốt');
        $target = $this->createProduct('Paracetamol 650mg', 'Paracetamol 650mg', 'Giảm đau, hạ sốt');

        $matched = $this->service->khopSanPham([[
            'name' => 'Paracetamol 650mg',
            'dosage' => '650mg',
            'active_ingredients' => ['Paracetamol'],
        ]]);

        $this->assertTrue($matched[0]['matched']);
        $this->assertSame($target->ma_sp, $matched[0]['product']['id']);
    }

    public function test_cung_san_pham_hai_lo_khong_bi_co_la_nhap_ngu(): void
    {
        $earliest = SanPham::factory()->create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Vitamin C 1000mg',
            'thanh_phan' => 'Acid ascorbic 1000mg',
            'so_luong_ton' => 10,
            'ngay_het_han' => now()->addMonths(3),
        ]);
        SanPham::factory()->create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Vitamin C 1000mg',
            'thanh_phan' => 'Acid ascorbic 1000mg',
            'so_luong_ton' => 40,
            'ngay_het_han' => now()->addYears(2),
        ]);

        $matched = $this->service->khopSanPham([[
            'name' => 'Blackmores Bio C',
            'dosage' => '1000mg',
        ]]);

        $this->assertTrue($matched[0]['matched']);
        $this->assertSame($earliest->ma_sp, $matched[0]['product']['id'], 'FEFO batch should win');
    }
}
