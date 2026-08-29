<?php

namespace Tests\Feature\Admin;

use App\Exports\AdminInventoryExport;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The inventory screen and its Excel export must answer the same question.
 * Previously the screen ran a client-side DataTables filter over one paginated page
 * while the export ran a server-side LIKE over the whole table, so a search could
 * show rows on screen and export none (or the reverse).
 */
class InventorySearchExportParityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'Administrator', 'status' => 'active']);

        $paracetamol = Category::create(['name' => 'Thuốc giảm đau', 'description' => 'Giảm đau']);
        $vitamin = Category::create(['name' => 'Vitamin', 'description' => 'Bổ sung']);

        Product::create([
            'MaDM' => $paracetamol->MaDM,
            'name' => 'Paracetamol 500mg',
            'price' => 20000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'LOT-AAA',
        ]);

        Product::create([
            'MaDM' => $vitamin->MaDM,
            'name' => 'Vitamin C 1000mg',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 5,
            'reorder_point' => 20,
            'batch_number' => 'LOT-BBB',
        ]);
    }

    public function test_the_screen_and_the_export_return_the_same_rows_for_a_search(): void
    {
        $onScreen = $this->actingAs($this->admin)
            ->get(route('admin.inventory.index', ['q' => 'Paracetamol']))
            ->assertOk()
            ->viewData('products')
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $exported = (new AdminInventoryExport(search: 'Paracetamol'))
            ->collection()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['Paracetamol 500mg'], $onScreen);
        $this->assertSame($onScreen, $exported);
    }

    public function test_the_search_matches_batch_number_and_category_name(): void
    {
        foreach (['LOT-BBB', 'Vitamin'] as $term) {
            $names = $this->actingAs($this->admin)
                ->get(route('admin.inventory.index', ['q' => $term]))
                ->assertOk()
                ->viewData('products')
                ->pluck('name')
                ->all();

            $this->assertSame(['Vitamin C 1000mg'], $names, "search term: {$term}");
        }
    }

    public function test_the_status_filter_agrees_between_screen_and_export(): void
    {
        $onScreen = $this->actingAs($this->admin)
            ->get(route('admin.inventory.index', ['status' => 'low_stock']))
            ->assertOk()
            ->viewData('products')
            ->pluck('name')
            ->all();

        $exported = (new AdminInventoryExport(status: 'low_stock'))
            ->collection()
            ->pluck('name')
            ->all();

        $this->assertSame(['Vitamin C 1000mg'], $onScreen);
        $this->assertSame($onScreen, $exported);
    }

    public function test_the_search_and_the_status_filter_compose(): void
    {
        $names = $this->actingAs($this->admin)
            ->get(route('admin.inventory.index', ['status' => 'low_stock', 'q' => 'Paracetamol']))
            ->assertOk()
            ->viewData('products')
            ->pluck('name')
            ->all();

        // Paracetamol is well stocked, so the two filters together match nothing.
        $this->assertSame([], $names);
    }
}
