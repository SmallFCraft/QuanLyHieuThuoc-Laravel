<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'code')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->string('code', 10)->nullable()->after('name');
            });
        }

        if (! Schema::hasColumn('products', 'product_code')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('product_code', 16)->nullable()->after('MaSP');
            });
        }

        $makeCategoryCode = function (string $name): string {
            $ascii = Str::ascii($name);
            $words = preg_split('/[^A-Za-z0-9]+/', strtoupper($ascii), -1, PREG_SPLIT_NO_EMPTY);
            $letters = collect($words ?: [])
                ->filter(fn (string $word) => ! in_array($word, ['VA', 'AND', 'THE'], true))
                ->map(fn (string $word) => $word[0])
                ->implode('');

            if ($letters === '') {
                $letters = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $ascii), 0, 3));
            }

            return substr($letters ?: 'DM', 0, 10);
        };

        $usedCategoryCodes = [];
        DB::table('categories')->orderBy('MaDM')->get(['MaDM', 'name', 'code'])->each(function ($category) use (&$usedCategoryCodes, $makeCategoryCode): void {
            $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($category->code ?: $makeCategoryCode($category->name))), 0, 10)) ?: 'DM';
            $code = $base;
            $suffix = 2;

            while (isset($usedCategoryCodes[$code])) {
                $tail = (string) $suffix++;
                $code = substr($base, 0, max(1, 10 - strlen($tail))) . $tail;
            }

            $usedCategoryCodes[$code] = true;
            DB::table('categories')->where('MaDM', $category->MaDM)->update(['code' => $code]);
        });

        $categoryCodes = DB::table('categories')->pluck('code', 'MaDM');
        $usedProductCodes = [];
        DB::table('products')->orderBy('MaSP')->get(['MaSP', 'MaDM', 'product_code'])->each(function ($product) use ($categoryCodes, &$usedProductCodes): void {
            $prefix = $categoryCodes[$product->MaDM] ?? 'SP';
            $candidate = $product->product_code ?: $prefix . str_pad((string) (((int) $product->MaSP) % 100000), 5, '0', STR_PAD_LEFT);
            $candidate = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($candidate)), 0, 16)) ?: $prefix . '00000';
            $code = $candidate;
            $counter = 1;

            while (isset($usedProductCodes[$code])) {
                $code = $prefix . str_pad((string) $counter++, 5, '0', STR_PAD_LEFT);
            }

            $usedProductCodes[$code] = true;
            DB::table('products')->where('MaSP', $product->MaSP)->update(['product_code' => $code]);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->unique('code', 'categories_code_unique');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unique('product_code', 'products_product_code_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'product_code')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropUnique('products_product_code_unique');
                $table->dropColumn('product_code');
            });
        }

        if (Schema::hasColumn('categories', 'code')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropUnique('categories_code_unique');
                $table->dropColumn('code');
            });
        }
    }
};
