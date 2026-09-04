<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The crawl produced "Cơ - xương - khớp" and "Cơ xương khớp" as two categories,
 * splitting 114 products across two storefront entries. The unique index on
 * categories.name_folded now stops that, and the admin form has to report it as a
 * field error rather than let the driver raise a 500.
 */
class CategoryDuplicateNameTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'Administrator', 'status' => 'active']);
    }

    public function test_a_name_differing_only_by_punctuation_is_rejected(): void
    {
        Category::create(['name' => 'Cơ - xương - khớp']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.categories.store'), ['name' => 'Cơ xương khớp'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, Category::count());
    }

    public function test_a_name_differing_only_by_accents_is_rejected(): void
    {
        Category::create(['name' => 'Thuốc dị ứng']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.categories.store'), ['name' => 'thuoc di ung'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_a_genuinely_new_name_is_accepted(): void
    {
        Category::create(['name' => 'Cơ - xương - khớp']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.categories.store'), ['name' => 'Thuốc hô hấp'])
            ->assertStatus(201);

        $this->assertSame(2, Category::count());
        $this->assertSame('thuoc ho hap', Category::firstWhere('name', 'Thuốc hô hấp')->name_folded);
    }

    /** Renaming must not trip over the row being renamed. */
    public function test_a_category_can_be_saved_under_its_own_folded_name(): void
    {
        $category = Category::create(['name' => 'Cơ - xương - khớp']);

        $this->actingAs($this->admin)
            ->putJson(route('admin.categories.update', $category), ['name' => 'Cơ xương khớp'])
            ->assertOk();

        $this->assertSame('Cơ xương khớp', $category->fresh()->name);
    }
}
