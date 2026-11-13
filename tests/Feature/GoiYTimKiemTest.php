<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\SanPham;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The autocomplete endpoint fires on every keystroke past a 300ms debounce, so it
 * has to be both cheap and consistent with the results page the customer lands on.
 */
class GoiYTimKiemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $painRelief = DanhMuc::create(['ten' => 'Thuốc giảm đau, hạ sốt']);
        $skincare = DanhMuc::create(['ten' => 'Chăm sóc da mặt']);

        $this->make($painRelief, 'Viên nén Paracetamol Stada 500mg', 'Paracetamol 500mg');
        $this->make($painRelief, 'Viên nén Panadol Extra đỏ GSK', 'Caffeine 65mg, Paracetamol 500mg');
        $this->make($skincare, 'Kem dưỡng ẩm Ceradan', 'Ceramide');
    }

    private function make(DanhMuc $danhMuc, string $name, string $ingredients): SanPham
    {
        return SanPham::create(['ma_dm' => $danhMuc->ma_dm,
            'ten' => $name,
            'thanh_phan' => $ingredients,
            'gia' => 50000,
            'ngay_het_han' => now()->addYears(2),
            'so_luong_ton' => 30,
            'muc_canh_bao' => 10,
            'so_lo' => 'LC-' . md5($name),
        ]);
    }

    private function suggest(string $term): array
    {
        return $this->getJson(route('san-pham.goi-y', ['q' => $term]))
            ->assertOk()
            ->json();
    }

    public function test_goi_y_san_pham_khop_kem_cac_truong_hien_thi(): void
    {
        $data = $this->suggest('panadol');

        $this->assertCount(1, $data['products']);
        $this->assertSame('Viên nén Panadol Extra đỏ GSK', $data['products'][0]['name']);
        $this->assertSame('Thuốc giảm đau, hạ sốt', $data['products'][0]['category']);
        $this->assertArrayHasKey('price', $data['products'][0]);
        $this->assertArrayHasKey('url', $data['products'][0]);
    }

    public function test_truy_van_khong_dau_goi_y_san_pham_co_dau(): void
    {
        $data = $this->suggest('duong am');

        $this->assertSame('Kem dưỡng ẩm Ceradan', $data['products'][0]['name']);
    }

    public function test_goi_y_duoc_xep_hang_giong_trang_ket_qua(): void
    {
        $names = collect($this->suggest('paracetamol')['products'])->pluck('name')->all();

        $this->assertSame('Viên nén Paracetamol Stada 500mg', $names[0]);
    }

    public function test_truy_van_mot_ky_tu_bi_tu_choi(): void
    {
        // "đ" is two bytes, so the old strlen() gate let it through and matched most
        // of the catalog on one keystroke.
        foreach (['h', 'đ', 'ô'] as $term) {
            $data = $this->suggest($term);

            $this->assertSame([], $data['products'], "Query '{$term}' should be refused.");
            $this->assertSame([], $data['categories']);
        }
    }

    public function test_hinh_dang_on_dinh_khi_khong_co_ket_qua_khop(): void
    {
        // The JS destructures products and categories; an empty top-level array
        // used to arrive instead.
        $data = $this->suggest('khong-ton-tai-gi-ca');

        $this->assertSame([], $data['products']);
        $this->assertSame([], $data['categories']);
    }

    public function test_danh_muc_khop_duoc_goi_y(): void
    {
        $data = $this->suggest('cham soc da');

        $this->assertSame('Chăm sóc da mặt', $data['categories'][0]['name']);
    }

    public function test_danh_muc_khong_con_hang_khong_duoc_goi_y(): void
    {
        DanhMuc::create(['ten' => 'Danh mục rỗng']);

        $data = $this->suggest('danh muc rong');

        $this->assertSame([], $data['categories']);
    }

    public function test_san_pham_het_hang_khong_duoc_goi_y(): void
    {
        SanPham::where('ten', 'like', 'Viên nén Panadol%')->update(['so_luong_ton' => 0]);

        $this->assertSame([], $this->suggest('panadol')['products']);
    }

    public function test_tra_loi_trong_ngan_sach_truy_van(): void
    {
        // One keystroke must not fan out into a query per category.
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $this->suggest('paracetamol');

        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, $queries, "Suggestions ran {$queries} queries.");
    }
}
