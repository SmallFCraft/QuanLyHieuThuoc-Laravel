<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $canonicalCategory = DB::table('categories')
            ->where('name', 'Vitamin và khoáng chất')
            ->first(['id']);

        $duplicateCategory = DB::table('categories')
            ->where('name', 'Vitamin')
            ->first(['id']);

        if (!$canonicalCategory || !$duplicateCategory) {
            return;
        }

        $now = Carbon::now();

        DB::table('products')
            ->where('category_id', $duplicateCategory->id)
            ->update([
                'category_id' => $canonicalCategory->id,
                'updated_at' => $now,
            ]);

        DB::table('categories')
            ->where('id', $duplicateCategory->id)
            ->delete();

        Cache::forget(Category::NAV_CACHE_KEY);
        Cache::forget(Category::FOOTER_CACHE_KEY);
        Cache::forget(Category::FILTER_CACHE_KEY);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally no-op: merged category data should not be re-split automatically.
    }
};

