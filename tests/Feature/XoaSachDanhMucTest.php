<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\TinNhanChat;
use App\Models\TuongTacThuoc;
use App\Models\CoDoYTe;
use App\Models\TrieuChungYTe;
use App\Models\QuyTacPhanTangYTe;
use App\Models\DonHang;
use App\Models\ChiTietDonHang;
use App\Models\SanPham;
use App\Models\LoSanPham;
use App\Models\TruyVanTimKiem;
use App\Models\NguoiDung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class XoaSachDanhMucTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The command deletes files under the public disk, so every test in this class
     * runs against a faked one — without this, a suite run wipes the developer's
     * real storage/app/public/products.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * A catalog with an order against it — the case where a plain delete on
     * products fails, because order_items.MaSP restricts.
     */
    private function seedCatalogWithAnOrder(): SanPham
    {
        $sanPham = SanPham::factory()->create(['ma_dm' => DanhMuc::factory()]);
        LoSanPham::create(['ma_sp' => $sanPham->ma_sp,
            'so_lo' => 'B-1',
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 10,
            'muc_canh_bao' => 5,
        ]);

        $donHang = DonHang::create(['ma_nd' => NguoiDung::factory()->create()->ma_nd,
            'tong_tien' => 1000,
            'trang_thai' => 'Pending',
            'dia_chi_giao_hang' => '1 Test',
            'sdt_giao_hang' => '0901234567',
        ]);

        ChiTietDonHang::create(['ma_dh' => $donHang->ma_dh,
            'ma_sp' => $sanPham->ma_sp,
            'so_luong' => 1,
            'don_gia' => 1000,
            'so_lo' => 'B-1',
        ]);

        return $sanPham;
    }

    public function test_xoa_sach_danh_muc_va_cac_don_hang_tham_chieu_den_no(): void
    {
        $this->seedCatalogWithAnOrder();

        $this->artisan('db:wipe-catalog --force')->assertSuccessful();

        $this->assertSame(0, SanPham::withTrashed()->count());
        $this->assertSame(0, LoSanPham::count());
        $this->assertSame(0, DanhMuc::count());
        $this->assertSame(0, DonHang::count());
        $this->assertSame(0, ChiTietDonHang::count());
    }

    public function test_giu_nguoi_dung_va_kien_thuc_y_khoa(): void
    {
        $this->seedCatalogWithAnOrder();
        $userCount = NguoiDung::count();

        // Nội dung y khoa do người soạn tay, không crawl lại được. Nó không nằm trong
        // TABLES, nhưng chỉ assert NguoiDung::count() thì thêm một bảng y khoa vào danh sách
        // đó — điều rất dễ xảy ra khi một bảng có thêm khoá ngoại tới products — vẫn
        // xanh trong khi dữ liệu mất vĩnh viễn.
        $trieuChung = TrieuChungYTe::create([
            'code' => 'sot',
            'name_vi' => 'Sốt',
            'aliases' => ['sot cao'],
            'guard_words' => [],
            'body_system' => 'general',
        ]);

        CoDoYTe::create([
            'code' => 'cung-co',
            'name_vi' => 'Cứng cổ',
            'keywords' => ['cung co'],
            'severity' => 'emergency',
            'guidance_vi' => 'Gọi 115.',
        ]);

        QuyTacPhanTangYTe::create([
            'ma_tc' => $trieuChung->ma_tc,
            'conditions' => [],
            'outcome' => 'self_care',
            'advice_vi' => 'Nghỉ ngơi, uống nhiều nước.',
        ]);

        TuongTacThuoc::create([
            'ingredient_a' => 'paracetamol',
            'ingredient_b' => 'warfarin',
            'severity' => 'moderate',
            'description_vi' => 'Tăng nguy cơ chảy máu.',
            'recommendation_vi' => 'Hỏi dược sĩ.',
        ]);

        $this->artisan('db:wipe-catalog --force')->assertSuccessful();

        $this->assertSame($userCount, NguoiDung::count());
        $this->assertSame(1, TrieuChungYTe::count());
        $this->assertSame(1, CoDoYTe::count());
        $this->assertSame(1, QuyTacPhanTangYTe::count());
        $this->assertSame(1, TuongTacThuoc::count());
    }

    public function test_xoa_sach_nhat_ky_tim_kiem_de_xu_huong_khong_con_ten_san_pham_da_mat(): void
    {
        TruyVanTimKiem::create([
            'term_folded' => 'paracetamol',
            'term' => 'paracetamol',
            'hits' => 9,
            'last_searched_at' => now(),
        ]);
        TruyVanTimKiem::trending();

        $this->artisan('db:wipe-catalog --force')->assertSuccessful();

        $this->assertSame(0, TruyVanTimKiem::count());
        // Cache cũng phải rơi: pill vẫn hiện 10 phút nữa dù bảng đã trống.
        $this->assertFalse(Cache::has(TruyVanTimKiem::CACHE_KEY));
    }

    public function test_tu_choi_xac_nhan_khong_thay_doi_gi(): void
    {
        $this->seedCatalogWithAnOrder();

        $this->artisan('db:wipe-catalog')
            ->expectsConfirmation('Tiếp tục?', 'no')
            ->assertSuccessful();

        $this->assertSame(1, SanPham::count());
        $this->assertSame(1, DonHang::count());
    }

    public function test_xoa_cac_anh_san_pham_da_tai_len(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/paracetamol.jpg', 'image');

        $this->artisan('db:wipe-catalog --force')->assertSuccessful();

        Storage::disk('public')->assertMissing('products/paracetamol.jpg');
    }

    public function test_giu_anh_khong_dong_den_cac_tap_tin(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/paracetamol.jpg', 'image');

        $this->artisan('db:wipe-catalog --force --keep-images')->assertSuccessful();

        Storage::disk('public')->assertExists('products/paracetamol.jpg');
    }

    public function test_snapshot_san_pham_tren_ban_ghi_con_song_duoc_lam_rong(): void
    {
        $sanPham = $this->seedCatalogWithAnOrder();

        $tinNhan = TinNhanChat::create([
            'ma_nd' => NguoiDung::factory()->create()->ma_nd,
            'session_id' => (string) \Illuminate\Support\Str::uuid(),
            'role' => 'assistant',
            'content' => 'Sản phẩm gợi ý',
            'products' => [['id' => $sanPham->ma_sp, 'name' => $sanPham->ten]],
        ]);

        $this->artisan('db:wipe-catalog --force')->assertSuccessful();

        // The message outlives the catalog, so its stored product block has to stop
        // linking to ids that no longer exist.
        $this->assertSame([], $tinNhan->fresh()->products);
    }

    public function test_id_khoi_dong_lai_de_danh_muc_moi_danh_so_tu_mot(): void
    {
        $this->seedCatalogWithAnOrder();

        $this->artisan('db:wipe-catalog --force')->assertSuccessful();

        $fresh = SanPham::factory()->create(['ma_dm' => DanhMuc::factory()]);

        $this->assertSame(1, $fresh->ma_sp);
    }

    public function test_chay_duoc_tren_co_so_du_lieu_da_trong(): void
    {
        $this->artisan('db:wipe-catalog --force')->assertSuccessful();

        $this->assertSame(0, SanPham::count());
    }
}
