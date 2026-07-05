<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_use_favorite_api(): void
    {
        $product = Product::factory()->create();

        $response = $this->postJson(route('favorites.toggle', $product));

        $response->assertUnauthorized();
    }

    public function test_user_can_toggle_favorite_product(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $saveResponse = $this
            ->actingAs($user)
            ->postJson(route('favorites.toggle', $product));

        $saveResponse
            ->assertOk()
            ->assertJson([
                'product_id' => (string) $product->MaSP,
                'saved' => true,
                'favorites' => [(string) $product->MaSP],
            ]);

        $this->assertDatabaseHas('favorites', [
            'MaKH' => $user->MaKH,
            'MaSP' => $product->MaSP,
        ]);

        $removeResponse = $this
            ->actingAs($user)
            ->postJson(route('favorites.toggle', $product));

        $removeResponse
            ->assertOk()
            ->assertJson([
                'product_id' => (string) $product->MaSP,
                'saved' => false,
                'favorites' => [],
            ]);

        $this->assertDatabaseMissing('favorites', [
            'MaKH' => $user->MaKH,
            'MaSP' => $product->MaSP,
        ]);
    }

    public function test_user_can_sync_local_favorites_to_database(): void
    {
        $user = User::factory()->create();
        $products = Product::factory()->count(2)->create();

        $response = $this
            ->actingAs($user)
            ->postJson(route('favorites.sync'), [
                'product_ids' => $products->pluck('MaSP')->all(),
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'synced_count' => 2,
                'favorites' => $products->pluck('MaSP')->map(fn ($id) => (string) $id)->values()->all(),
            ]);

        $this->assertSame(2, Favorite::where('MaKH', $user->MaKH)->count());
    }

    public function test_profile_shows_favorite_products(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'name' => 'Vitamin Sync 500mg',
        ]);

        Favorite::create([
            'MaKH' => $user->MaKH,
            'MaSP' => $product->MaSP,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit') . '#favorites');

        $response
            ->assertOk()
            ->assertSee('data-profile-favorites', false)
            ->assertSee('Vitamin Sync 500mg');
    }

    public function test_user_can_refresh_favorite_section_without_full_page_reload(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'name' => 'Ajax Favorite 250mg',
        ]);

        Favorite::create([
            'MaKH' => $user->MaKH,
            'MaSP' => $product->MaSP,
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson(route('favorites.section'));

        $response
            ->assertOk()
            ->assertJson([
                'favorites' => [(string) $product->MaSP],
            ]);

        $this->assertStringContainsString('data-profile-favorites', $response->json('html'));
        $this->assertStringContainsString('Ajax Favorite 250mg', $response->json('html'));
    }
}
