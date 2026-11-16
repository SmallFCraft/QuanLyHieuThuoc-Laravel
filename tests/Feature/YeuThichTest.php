<?php

namespace Tests\Feature;

use App\Models\YeuThich;
use App\Models\SanPham;
use App\Models\NguoiDung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YeuThichTest extends TestCase
{
    use RefreshDatabase;

    public function test_khach_vo_danh_khong_dung_duoc_api_yeu_thich(): void
    {
        $sanPham = SanPham::factory()->create();

        $response = $this->postJson(route('yeu-thich.toggle', $sanPham));

        $response->assertUnauthorized();
    }

    public function test_nguoi_dung_bat_tat_yeu_thich_san_pham(): void
    {
        $nguoiDung = NguoiDung::factory()->create();
        $sanPham = SanPham::factory()->create();

        $saveResponse = $this
            ->actingAs($nguoiDung)
            ->postJson(route('yeu-thich.toggle', $sanPham));

        $saveResponse
            ->assertOk()
            ->assertJson([
                'product_id' => (string) $sanPham->ma_sp,
                'saved' => true,
                'favorites' => [(string) $sanPham->ma_sp],
            ]);

        $this->assertDatabaseHas('yeu_thiches', [
            'ma_nd' => $nguoiDung->ma_nd,
            'ma_sp' => $sanPham->ma_sp,
        ]);

        $removeResponse = $this
            ->actingAs($nguoiDung)
            ->postJson(route('yeu-thich.toggle', $sanPham));

        $removeResponse
            ->assertOk()
            ->assertJson([
                'product_id' => (string) $sanPham->ma_sp,
                'saved' => false,
                'favorites' => [],
            ]);

        $this->assertDatabaseMissing('yeu_thiches', [
            'ma_nd' => $nguoiDung->ma_nd,
            'ma_sp' => $sanPham->ma_sp,
        ]);
    }

    public function test_nguoi_dung_dong_bo_yeu_thich_cuc_bo_vao_co_so_du_lieu(): void
    {
        $nguoiDung = NguoiDung::factory()->create();
        $sanPhams = SanPham::factory()->count(2)->create();

        $response = $this
            ->actingAs($nguoiDung)
            ->postJson(route('yeu-thich.sync'), [
                'product_ids' => $sanPhams->pluck('ma_sp')->all(),
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'synced_count' => 2,
                'favorites' => $sanPhams->pluck('ma_sp')->map(fn ($id) => (string) $id)->values()->all(),
            ]);

        $this->assertSame(2, YeuThich::where('ma_nd', $nguoiDung->ma_nd)->count());
    }

    public function test_ho_so_hien_san_pham_yeu_thich(): void
    {
        $nguoiDung = NguoiDung::factory()->create();
        $sanPham = SanPham::factory()->create(['ten' => 'Vitamin Sync 500mg',
        ]);

        YeuThich::create([
            'ma_nd' => $nguoiDung->ma_nd,
            'ma_sp' => $sanPham->ma_sp,
        ]);

        $response = $this
            ->actingAs($nguoiDung)
            ->get(route('ho-so.edit') . '#favorites');

        $response
            ->assertOk()
            ->assertSee('data-profile-favorites', false)
            ->assertSee('Vitamin Sync 500mg');
    }

    public function test_nguoi_dung_lam_moi_muc_yeu_thich_khong_can_tai_lai_ca_trang(): void
    {
        $nguoiDung = NguoiDung::factory()->create();
        $sanPham = SanPham::factory()->create(['ten' => 'Ajax Favorite 250mg',
        ]);

        YeuThich::create([
            'ma_nd' => $nguoiDung->ma_nd,
            'ma_sp' => $sanPham->ma_sp,
        ]);

        $response = $this
            ->actingAs($nguoiDung)
            ->getJson(route('yeu-thich.section'));

        $response
            ->assertOk()
            ->assertJson([
                'favorites' => [(string) $sanPham->ma_sp],
            ]);

        $this->assertStringContainsString('data-profile-favorites', $response->json('html'));
        $this->assertStringContainsString('Ajax Favorite 250mg', $response->json('html'));
    }
}
