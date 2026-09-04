<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four metric cards at the top of the inventory screen have to show the counts
 * the service computed. They read their own key names, so a rename on either side
 * silently falls back to the `?? 0` default and every card reads zero while the
 * table below it lists the affected rows.
 */
class InventorySummaryCardsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'Administrator', 'status' => 'active']);

        $category = Category::create(['name' => 'Thuốc giảm đau']);

        $make = function (string $name, string $batch, int $stock, int $reorder, string $expiry) use ($category) {
            return Product::create([
                'MaDM' => $category->MaDM,
                'name' => $name,
                'price' => 20000,
                'expiration_date' => $expiry,
                'stock_quantity' => $stock,
                'reorder_point' => $reorder,
                'batch_number' => $batch,
            ]);
        };

        $make('Bình thường', 'LOT-OK', 100, 10, now()->addYears(3)->toDateString());
        $make('Tồn kho thấp', 'LOT-LOW', 5, 20, now()->addYears(3)->toDateString());
        $make('Sắp hết hạn', 'LOT-EXP', 80, 10, now()->addDays(20)->toDateString());
        $make('Hết hàng', 'LOT-ZERO', 0, 10, now()->addYears(3)->toDateString());
    }

    public function test_the_cards_report_the_counts_the_service_computed(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.inventory.index'));

        $response->assertOk();

        $summary = $response->viewData('summary');

        $this->assertSame(4, $summary['total_products']);
        $this->assertSame(2, $summary['low_stock'], 'Zero stock is also below its reorder point.');
        $this->assertSame(1, $summary['expiring_soon']);
        $this->assertSame(1, $summary['out_of_stock']);
    }

    public function test_every_key_the_view_reads_exists_in_the_summary(): void
    {
        $summary = $this->actingAs($this->admin)
            ->get(route('admin.inventory.index'))
            ->viewData('summary');

        foreach (['total_products', 'low_stock', 'expiring_soon', 'out_of_stock'] as $key) {
            $this->assertArrayHasKey($key, $summary);
        }
    }

    public function test_a_nonzero_count_is_rendered_on_the_card(): void
    {
        // Guards the whole path, not just the array: a card falling back to `?? 0`
        // renders "0" next to a table that lists the rows it is counting.
        //
        // Anchored on the card's own label — the title-case low_stock_label — and on the
        // value markup next to it. A bare "Tồn kho thấp" is status_low_stock, a different
        // string that the row badge and the filter prose already supply.
        $html = $this->actingAs($this->admin)
            ->get(route('admin.inventory.index', ['status' => 'low_stock']))
            ->assertOk()
            ->getContent();

        $label = __('messages.admin.inventory.low_stock_label');
        $card = substr($html, (int) strpos($html, $label));

        $this->assertStringContainsString($label, $html);
        $this->assertStringContainsString('<div class="value">2</div>', $card);
    }
}
