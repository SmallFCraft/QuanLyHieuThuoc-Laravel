<?php

namespace Tests\Feature;

use App\Models\YeuThich;
use App\Models\SanPham;
use App\Models\NguoiDung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_use_favorite_api(): void
    {
        $sanPham = SanPham::factory()->create();

        $response = $this->postJson(route('favorites.toggle', $sanPham));

        $response->assertUnauthorized();
    }

    public function test_user_can_toggle_favorite_product(): void
    {
        $nguoiDung = NguoiDung::factory()->create();
        $sanPham = SanPham::factory()->create();

        $saveResponse = $this
            ->actingAs($nguoiDung)
            ->postJson(route('favorites.toggle', $sanPham));

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
            ->postJson(route('favorites.toggle', $sanPham));

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

    public function test_user_can_sync_local_favorites_to_database(): void
    {
        $nguoiDung = NguoiDung::factory()->create();
        $sanPhams = SanPham::factory()->count(2)->create();

        $response = $this
            ->actingAs($nguoiDung)
            ->postJson(route('favorites.sync'), [
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

    public function test_profile_shows_favorite_products(): void
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
            ->get(route('profile.edit') . '#favorites');

        $response
            ->assertOk()
            ->assertSee('data-profile-favorites', false)
            ->assertSee('Vitamin Sync 500mg');
    }

    public function test_user_can_refresh_favorite_section_without_full_page_reload(): void
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
            ->getJson(route('favorites.section'));

        $response
            ->assertOk()
            ->assertJson([
                'favorites' => [(string) $sanPham->ma_sp],
            ]);

        $this->assertStringContainsString('data-profile-favorites', $response->json('html'));
        $this->assertStringContainsString('Ajax Favorite 250mg', $response->json('html'));
    }
}
