<?php

namespace Tests\Unit;

use App\Models\SanPham;
use App\Models\LoSanPham;
use App\Models\DanhMuc;
use App\Models\NguoiDung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $danhMuc;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->danhMuc = DanhMuc::create(['ten' => 'Test Category',
            'mo_ta' => 'Test Description'
        ]);
    }

    public function test_product_can_be_created_with_all_required_fields(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Medicine',
            'thanh_phan' => 'Active ingredient',
            'cach_dung' => 'Take twice daily',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-001'
        ]);

        $this->assertDatabaseHas('san_phams', [
            'ten' => 'Test Medicine',
            'so_lo' => 'BATCH-001'
        ]);
    }

    public function test_product_rejects_negative_price(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Medicine',
            'gia' => -1000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-002'
        ]);
    }

    public function test_product_rejects_negative_stock_quantity(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => -10,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-003'
        ]);
    }

    public function test_product_rejects_negative_reorder_point(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 10,
            'muc_canh_bao' => -1,
            'so_lo' => 'BATCH-003A'
        ]);
    }

    public function test_product_batch_rejects_negative_stock_quantity(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-003B'
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        LoSanPham::create(['ma_sp' => $sanPham->ma_sp,
            'so_lo' => 'BATCH-003C',
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => -5,
            'muc_canh_bao' => 10,
        ]);
    }

    public function test_product_expiration_date_must_be_future_date(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->addMonths(3),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-004'
        ]);

        $this->assertTrue($sanPham->ngay_het_han->isFuture());
    }

    public function test_product_shows_expiration_warning_when_less_than_six_months(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->addMonths(3),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-005'
        ]);

        $this->assertTrue($sanPham->isExpiringSoon(180));
    }

    public function test_a_distant_expiry_is_not_flagged_as_expiring_soon(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Medicine Far',
            'gia' => 50000,
            'ngay_het_han' => now()->addMonths(36),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-005-FAR',
        ]);

        // The signed Carbon 3 difference made this return true for every future
        // date, so the inventory screen labelled the whole catalog "Sắp hết hạn".
        $this->assertFalse($sanPham->isExpiringSoon(30));
        $this->assertFalse($sanPham->isExpiringSoon(180));

        $lo = LoSanPham::where('ma_sp', $sanPham->ma_sp)->sole();
        $this->assertFalse($lo->isExpiringSoon(30));
    }

    public function test_the_expiring_soon_boundary_matches_the_query_scope(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Medicine Boundary',
            'gia' => 50000,
            'ngay_het_han' => now()->addDays(20),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-005-EDGE',
        ]);

        // The summary cards count with scopeExpiringSoon() while the table labels
        // rows with isExpiringSoon(); disagreement shows a wrong count next to the
        // rows it claims to describe.
        $this->assertTrue($sanPham->isExpiringSoon(30));
        $this->assertSame(1, SanPham::expiringSoon(30)->count());
    }

    public function test_batch_number_must_be_unique_per_product(): void
    {
        SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Medicine A',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-UNIQUE'
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Same product name and batch number should fail
        SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Medicine A',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-UNIQUE'
        ]);
    }

    public function test_product_update_creates_audit_log(): void
    {
        $admin = NguoiDung::factory()->create(['vai_tro' => 'Administrator',
            'trang_thai' => 'active'
        ]);

        $this->actingAs($admin);

        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-006'
        ]);

        $sanPham->update(['gia' => 60000]);

        $this->assertDatabaseHas('nhat_ky_kiem_toans', [
            'model_type' => SanPham::class,
            'MaDoiTuong' => $sanPham->ma_sp,
            'action' => 'updated',
            'ma_nd' => $admin->ma_nd
        ]);
    }

    public function test_product_soft_delete_creates_audit_log(): void
    {
        $admin = NguoiDung::factory()->create(['vai_tro' => 'Administrator',
            'trang_thai' => 'active'
        ]);

        $this->actingAs($admin);

        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-007'
        ]);

        $sanPham->delete();

        $this->assertSoftDeleted('san_phams', [
            'ma_sp' => $sanPham->ma_sp
        ]);

        $this->assertDatabaseHas('nhat_ky_kiem_toans', [
            'model_type' => SanPham::class,
            'MaDoiTuong' => $sanPham->ma_sp,
            'action' => 'deleted',
            'ma_nd' => $admin->ma_nd
        ]);
    }

    public function test_product_is_expired_method_works_correctly(): void
    {
        $expiredProduct = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Expired Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->subDay(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-008'
        ]);

        $validProduct = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Valid Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-009'
        ]);

        $this->assertTrue($expiredProduct->isExpired());
        $this->assertFalse($validProduct->isExpired());
    }

    public function test_product_is_low_stock_method_works_correctly(): void
    {
        $lowStockProduct = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Low Stock Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 5,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-010'
        ]);

        $normalStockProduct = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Normal Stock Medicine',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-011'
        ]);

        $this->assertTrue($lowStockProduct->isLowStock());
        $this->assertFalse($normalStockProduct->isLowStock());
    }
}
