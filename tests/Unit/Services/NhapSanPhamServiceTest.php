<?php

namespace Tests\Unit\Services;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\LoSanPham;
use App\Services\NhapSanPhamService;
use App\Support\LongChauProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NhapSanPhamServiceTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $overrides = []): LongChauProduct
    {
        return LongChauProduct::fromNextData(['props' => ['pageProps' => ['product' => array_merge([
            'sku' => '00005713',
            'webName' => 'Viên nén Panadol Extra đỏ GSK (15 vỉ x 12 viên)',
            'prices' => [['level' => 1, 'price' => 252000]],
            'categories' => [
                ['level' => 1, 'name' => 'Thuốc'],
                ['level' => 2, 'name' => 'Thuốc giảm đau, hạ sốt, kháng viêm'],
            ],
            'ingredient' => [
                ['name' => 'Caffeine', 'shortDescription' => '65mg'],
                ['name' => 'Paracetamol', 'shortDescription' => '500mg'],
            ],
            'usage' => '<h3>Chỉ định</h3><p>Giảm đau, hạ sốt.</p>',
            'primaryImage' => ['url' => 'https://cdn.nhathuoclongchau.com.vn/v1/static/panadol.png'],
            'expirationDate' => '36 tháng',
            'prescription' => false,
        ], $overrides)]]]);
    }

    private function importer(): NhapSanPhamService
    {
        return app(NhapSanPhamService::class);
    }

    public function test_luu_cac_truong_catalog_va_mot_lo_tam(): void
    {
        $this->importer()->nhap($this->product());

        $sanPham = SanPham::sole();

        $this->assertSame('Viên nén Panadol Extra đỏ GSK (15 vỉ x 12 viên)', $sanPham->ten);
        $this->assertSame(252000, $sanPham->price_vnd);
        $this->assertSame('Caffeine 65mg, Paracetamol 500mg', $sanPham->thanh_phan);
        // Stored as the CDN's resized variant, not the multi-megabyte master.
        $this->assertSame(
            'https://cdn.nhathuoclongchau.com.vn/unsafe/600x0/filters:quality(90):format(webp)/panadol.png',
            $sanPham->hinh_anh
        );
        $this->assertSame('LC-00005713', $sanPham->so_lo);
        $this->assertSame(
            'Thuốc giảm đau, hạ sốt, kháng viêm',
            $sanPham->danhMuc->ten
        );

        // ProductObserver turns so_lo + ngay_het_han into the batch row
        // that FEFO and the expiry warnings read.
        $lo = LoSanPham::sole();
        $this->assertSame($sanPham->ma_sp, $lo->ma_sp);
        $this->assertSame('LC-00005713', $lo->so_lo);
        $this->assertSame(
            $sanPham->ngay_het_han->toDateString(),
            $lo->ngay_het_han->toDateString()
        );
    }

    public function test_ngay_het_han_phan_anh_hsd_cong_bo(): void
    {
        $this->travelTo('2026-08-30');

        $this->importer()->nhap($this->product(['expirationDate' => '24 tháng']));

        $this->assertSame('2028-08-30', SanPham::sole()->ngay_het_han->toDateString());
    }

    public function test_ma_san_pham_duoc_sinh_tu_danh_muc(): void
    {
        $this->importer()->nhap($this->product());

        // SanPham::booted() derives it from the category code (ma_san_pham); the crawler must not
        // set it by hand.
        $this->assertNotNull(SanPham::sole()->ma_san_pham);
    }

    public function test_lan_quet_thu_hai_cap_nhat_tai_cho_thay_vi_nhan_ban(): void
    {
        $this->importer()->nhap($this->product());
        $this->importer()->nhap($this->product(['prices' => [['level' => 1, 'price' => 268000]]]));

        $this->assertSame(1, SanPham::count());
        $this->assertSame(1, LoSanPham::count());
        $this->assertSame(268000, SanPham::sole()->price_vnd);
    }

    public function test_quet_lai_khong_ghi_de_ton_kho_nhan_vien_da_nhap(): void
    {
        $this->importer()->nhap($this->product());

        $sanPham = SanPham::sole();
        $lo = LoSanPham::sole();

        // What a pharmacist would enter after real stock arrives.
        $lo->update(['so_luong_ton' => 42, 'ngay_het_han' => '2027-01-15']);
        $sanPham->forceFill([
            'so_luong_ton' => 42,
            'muc_canh_bao' => 7,
            'ngay_het_han' => '2027-01-15',
        ])->save();

        $this->importer()->nhap($this->product(['prices' => [['level' => 1, 'price' => 300000]]]));

        $lo->refresh();
        $sanPham->refresh();

        $this->assertSame(42, $lo->so_luong_ton, 'Real stock survives a recrawl.');
        $this->assertSame('2027-01-15', $lo->ngay_het_han->toDateString());
        $this->assertSame(7, $sanPham->muc_canh_bao);

        // san_phams.so_luong_ton là cột gác "Còn hàng"/"Hết hàng" và mức max của giỏ,
        // san_phams.ngay_het_han là cột gác notExpired() — cả hai đều phải sống sót,
        // không chỉ dòng batch.
        $this->assertSame(42, $sanPham->so_luong_ton, 'Storefront stock survives a recrawl.');
        $this->assertSame('2027-01-15', $sanPham->ngay_het_han->toDateString());

        $this->assertSame(300000, $sanPham->price_vnd, 'Catalog fields still refresh.');
    }

    public function test_danh_muc_duoc_tai_su_dung_giua_cac_san_pham(): void
    {
        $this->importer()->nhap($this->product());
        $this->importer()->nhap($this->product([
            'sku' => '00009999',
            'webName' => 'Thuốc Efferalgan 500mg',
        ]));

        $this->assertSame(1, DanhMuc::count());
        $this->assertSame(2, SanPham::count());
    }

    public function test_danh_muc_da_co_khop_theo_ten_chu_khong_nhan_ban(): void
    {
        DanhMuc::create(['ten' => 'Thuốc giảm đau, hạ sốt, kháng viêm']);

        $this->importer()->nhap($this->product());

        $this->assertSame(1, DanhMuc::count());
    }

    /**
     * Long Châu publishes the same level-2 breadcrumb under more than one spelling
     * ("Cơ - xương - khớp" / "Cơ xương khớp"), which split 114 products across two
     * storefront entries a customer reads as one.
     */
    public function test_cach_viet_danh_muc_khac_nhau_chi_vi_dau_cau_gop_mot_dong(): void
    {
        $spelt = fn (string $name, string $sku) => $this->product([
            'sku' => $sku,
            'categories' => [['level' => 1, 'name' => 'Thuốc'], ['level' => 2, 'name' => $name]],
        ]);

        $this->importer()->nhap($spelt('Cơ - xương - khớp', '00000001'));
        $this->importer()->nhap($spelt('Cơ xương khớp', '00000002'));
        $this->importer()->nhap($spelt('CƠ XƯƠNG KHỚP', '00000003'));

        $this->assertSame(1, DanhMuc::count());
        // First spelling seen keeps the display name.
        $this->assertSame('Cơ - xương - khớp', DanhMuc::sole()->ten);
        $this->assertSame(3, SanPham::where('ma_dm', DanhMuc::sole()->ma_dm)->count());
    }

    /** The per-run cache must not hand back a different row than the database would. */
    public function test_danh_muc_tao_truoc_khi_quet_khop_du_dung_moi_cach_viet(): void
    {
        DanhMuc::create(['ten' => 'Cơ xương khớp']);

        $this->importer()->nhap($this->product([
            'categories' => [['level' => 1, 'name' => 'Thuốc'], ['level' => 2, 'name' => 'Cơ - xương - khớp']],
        ]));

        $this->assertSame(1, DanhMuc::count());
        $this->assertSame('Cơ xương khớp', SanPham::sole()->danhMuc->ten);
    }

    public function test_san_pham_doi_danh_muc_duoc_chuyen_sang_danh_muc_moi(): void
    {
        $this->importer()->nhap($this->product());
        $this->importer()->nhap($this->product([
            'categories' => [
                ['level' => 1, 'name' => 'Thuốc'],
                ['level' => 2, 'name' => 'Thuốc tiêu hoá & gan mật'],
            ],
        ]));

        $this->assertSame(2, DanhMuc::count());
        $this->assertSame('Thuốc tiêu hoá & gan mật', SanPham::sole()->danhMuc->ten);
    }

    public function test_ton_kho_tao_ra_nam_trong_khoang_cau_hinh(): void
    {
        foreach (range(1, 15) as $i) {
            $this->importer()->nhap($this->product([
                'sku' => '0000' . $i,
                'webName' => "Thuốc số {$i}",
            ]));
        }

        foreach (SanPham::all() as $sanPham) {
            $this->assertGreaterThanOrEqual(0, $sanPham->so_luong_ton);
            $this->assertLessThanOrEqual(200, $sanPham->so_luong_ton);
            $this->assertGreaterThanOrEqual(10, $sanPham->muc_canh_bao);
            $this->assertLessThanOrEqual(30, $sanPham->muc_canh_bao);
        }
    }

    public function test_san_pham_da_xoa_mem_duoc_phuc_hoi_thay_vi_nhan_ban(): void
    {
        $this->importer()->nhap($this->product());
        SanPham::sole()->delete();

        $this->importer()->nhap($this->product());

        $this->assertSame(1, SanPham::withTrashed()->count());
        $this->assertNull(SanPham::sole()->deleted_at);
    }
}
