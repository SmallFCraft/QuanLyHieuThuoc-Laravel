<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\TruyVanTimKiem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Xu hướng tìm kiếm được dựng từ chính từ khoá khách đã gõ, nên nó vừa là dữ liệu
 * vừa là chữ hiện trên trang chủ. Hai việc đó kéo theo hai nhóm test: đếm đúng, và
 * không để chữ rác lọt ra giao diện.
 */
class XuHuongTimKiemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $danhMuc = DanhMuc::create(['ten' => 'Vitamin & Khoáng chất']);

        SanPham::create(['ma_dm' => $danhMuc->ma_dm,
            'ten' => 'Viên uống Vitamin C 1000mg DHC',
            'thanh_phan' => 'Vitamin C 1000mg',
            'gia' => 120000,
            'ngay_het_han' => now()->addYears(2),
            'so_luong_ton' => 40,
            'muc_canh_bao' => 10,
            'so_lo' => 'LC-vitc',
        ]);
    }

    private function search(string $term, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('san-pham.tim-kiem', ['q' => $term] + $extra))->assertOk();
    }

    public function test_luot_tim_thay_san_pham_duoc_dem(): void
    {
        $this->search('vitamin c');

        $row = TruyVanTimKiem::sole();

        $this->assertSame('vitamin c', $row->term_folded);
        $this->assertSame(1, $row->hits);
    }

    public function test_cung_tu_khoa_go_hai_lan_tao_mot_dong_voi_hai_luot(): void
    {
        $this->search('vitamin c');
        $this->search('Vitamin  C');

        // Gộp trên dạng đã fold: hoa/thường và khoảng trắng đôi là cùng một xu hướng.
        $this->assertSame(1, TruyVanTimKiem::count());
        $this->assertSame(2, TruyVanTimKiem::sole()->hits);
    }

    public function test_luot_tim_khong_thay_gi_khong_duoc_dem(): void
    {
        // Gõ sai chính tả là nguồn rác lớn nhất. Không có kết quả thì không phải
        // xu hướng — đưa nó lên trang chủ là mời người sau vào một trang trống.
        $this->search('khong co thuoc nay dau');

        $this->assertSame(0, TruyVanTimKiem::count());
    }

    public function test_tu_khoa_trong_danh_sach_den_khong_bao_gio_duoc_dem(): void
    {
        // Pill xu hướng là chữ khách gõ hiện trên trang chủ, nên nó đi qua đúng
        // blacklist mà chatbot dùng.
        SanPham::where('ma_sp', '>', 0)->update(['ten' => 'Thuốc ngu người', 'ten_khong_dau' => 'thuoc ngu nguoi']);

        $this->search('ngu');

        $this->assertSame(0, TruyVanTimKiem::count());
    }

    public function test_phan_trang_ket_qua_khong_lam_tang_so_dem(): void
    {
        $this->search('vitamin c');

        // Trang 2 vượt trang cuối (1 kết quả / 10 mỗi trang) nên là 404, và cũng
        // không được đếm — một request không render kết quả thì không phải lượt tìm.
        $this->get(route('san-pham.tim-kiem', ['q' => 'vitamin c', 'page' => 2]))
            ->assertNotFound();

        $this->assertSame(1, TruyVanTimKiem::sole()->hits);
    }

    public function test_yeu_cau_khoi_ajax_khong_duoc_dem(): void
    {
        $this->search('vitamin c');

        $this->getJson(route('san-pham.tim-kiem', ['q' => 'vitamin c']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();

        $this->assertSame(1, TruyVanTimKiem::sole()->hits);
    }

    public function test_truy_van_qua_dai_bi_tu_choi(): void
    {
        // Một đoạn văn dán vào ô tìm kiếm không phải từ khoá.
        $this->assertFalse(TruyVanTimKiem::record(str_repeat('vitamin ', 12)));
        $this->assertSame(0, TruyVanTimKiem::count());
    }

    public function test_xu_huong_xep_hang_theo_so_luot(): void
    {
        $this->seedTerm('collagen', 9);
        $this->seedTerm('khẩu trang', 4);
        $this->seedTerm('omega 3', 7);

        $trending = TruyVanTimKiem::trending(3)->all();

        $this->assertSame(['collagen', 'omega 3', 'khẩu trang'], $trending);
    }

    public function test_tu_khoa_duoi_nguong_luot_khong_thanh_xu_huong(): void
    {
        $this->seedTerm('chỉ một lượt', 1);

        $this->assertNotContains('chỉ một lượt', TruyVanTimKiem::trending()->all());
    }

    public function test_tu_khoa_cu_roi_khoi_cua_so_thoi_gian(): void
    {
        $this->seedTerm('mùa cũ', 50, now()->subDays(45));

        $this->assertNotContains('mùa cũ', TruyVanTimKiem::trending()->all());
    }

    public function test_danh_sach_seed_lap_nhat_ky_rong(): void
    {
        // Shop mới mở: panel trống trông như tính năng lỗi.
        $trending = TruyVanTimKiem::trending();

        $this->assertCount((int) config('search.trending.limit'), $trending);
        $this->assertSame(config('search.trending.seed'), $trending->all());
    }

    public function test_tu_khoa_that_dung_truoc_seed_va_khong_bi_seed_nhan_doi(): void
    {
        $this->seedTerm('Vitamin C', 12);

        $trending = TruyVanTimKiem::trending()->all();

        $this->assertSame('Vitamin C', $trending[0]);
        // "vitamin c" nằm trong seed; so trên dạng fold nên nó không hiện hai lần.
        $this->assertNotContains('vitamin c', $trending);
        $this->assertCount((int) config('search.trending.limit'), $trending);
    }

    public function test_ghi_tu_khoa_xoa_cache_xu_huong(): void
    {
        $this->seedTerm('collagen', 5);
        TruyVanTimKiem::trending();

        $this->seedTerm('collagen', 40);

        // Không xoá cache thì pill đứng yên tới 10 phút sau mỗi lượt tìm.
        $this->assertSame('collagen', TruyVanTimKiem::trending()->first());
    }

    private function seedTerm(string $term, int $hits, ?\Illuminate\Support\Carbon $when = null): void
    {
        TruyVanTimKiem::updateOrCreate(
            ['term_folded' => \App\Support\VietnameseText::fold($term)],
            ['term' => $term, 'hits' => $hits, 'last_searched_at' => $when ?? now()],
        );

        Cache::forget(TruyVanTimKiem::CACHE_KEY);
    }
}
