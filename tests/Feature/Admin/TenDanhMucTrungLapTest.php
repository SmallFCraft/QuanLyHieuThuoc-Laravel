<?php

namespace Tests\Feature\Admin;

use App\Models\DanhMuc;
use App\Models\NguoiDung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The crawl produced "Cơ - xương - khớp" and "Cơ xương khớp" as two categories,
 * splitting 114 products across two storefront entries. The unique index on
 * danh_mucs.ten_khong_dau now stops that, and the admin form has to report it as a
 * field error rather than let the driver raise a 500.
 */
class TenDanhMucTrungLapTest extends TestCase
{
    use RefreshDatabase;

    protected NguoiDung $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = NguoiDung::factory()->create(['vai_tro' => 'Administrator', 'trang_thai' => 'active']);
    }

    public function test_ten_chi_khac_dau_cham_lai_bi_tu_choi(): void
    {
        DanhMuc::create(['ten' => 'Cơ - xương - khớp']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.danh-muc.store'), ['name' => 'Cơ xương khớp'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, DanhMuc::count());
    }

    public function test_ten_chi_khac_dau_tieng_viet_lai_bi_tu_choi(): void
    {
        DanhMuc::create(['ten' => 'Thuốc dị ứng']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.danh-muc.store'), ['name' => 'thuoc di ung'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_ten_hoan_toan_moi_duoc_chap_nhan(): void
    {
        DanhMuc::create(['ten' => 'Cơ - xương - khớp']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.danh-muc.store'), ['name' => 'Thuốc hô hấp'])
            ->assertStatus(201);

        $this->assertSame(2, DanhMuc::count());
        $this->assertSame('thuoc ho hap', DanhMuc::firstWhere('ten', 'Thuốc hô hấp')->ten_khong_dau);
    }

    /** Renaming must not trip over the row being renamed. */
    public function test_danh_muc_luu_duoc_voi_ten_khong_dau_cua_chinh_no(): void
    {
        $danhMuc = DanhMuc::create(['ten' => 'Cơ - xương - khớp']);

        $this->actingAs($this->admin)
            ->putJson(route('admin.danh-muc.update', $danhMuc), ['name' => 'Cơ xương khớp'])
            ->assertOk();

        $this->assertSame('Cơ xương khớp', $danhMuc->fresh()->ten);
    }
}
