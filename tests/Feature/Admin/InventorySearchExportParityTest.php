<?php

namespace Tests\Feature\Admin;

use App\Exports\AdminInventoryExport;
use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\NguoiDung;
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

    protected NguoiDung $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = NguoiDung::factory()->create(['vai_tro' => 'Administrator', 'trang_thai' => 'active']);

        $paracetamol = DanhMuc::create(['ten' => 'Thuốc giảm đau', 'mo_ta' => 'Giảm đau']);
        $vitamin = DanhMuc::create(['ten' => 'Vitamin', 'mo_ta' => 'Bổ sung']);

        SanPham::create(['ma_dm' => $paracetamol->ma_dm,
            'ten' => 'Paracetamol 500mg',
            'gia' => 20000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'LOT-AAA',
        ]);

        SanPham::create(['ma_dm' => $vitamin->ma_dm,
            'ten' => 'Vitamin C 1000mg',
            'gia' => 50000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 5,
            'muc_canh_bao' => 20,
            'so_lo' => 'LOT-BBB',
        ]);
    }

    public function test_the_screen_and_the_export_return_the_same_rows_for_a_search(): void
    {
        $onScreen = $this->actingAs($this->admin)
            ->get(route('admin.inventory.index', ['q' => 'Paracetamol']))
            ->assertOk()
            ->viewData('products')
            ->pluck('ten')
            ->sort()
            ->values()
            ->all();

        $exported = (new AdminInventoryExport(search: 'Paracetamol'))
            ->collection()
            ->pluck('ten')
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
                ->pluck('ten')
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
            ->pluck('ten')
            ->all();

        $exported = (new AdminInventoryExport(status: 'low_stock'))
            ->collection()
            ->pluck('ten')
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
            ->pluck('ten')
            ->all();

        // Paracetamol is well stocked, so the two filters together match nothing.
        $this->assertSame([], $names);
    }
}
