<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Str;

class ProductCodeGenerator
{
    public static function categoryCodeFromName(string $name): string
    {
        $ascii = Str::ascii($name);
        $words = preg_split('/[^A-Za-z0-9]+/', strtoupper($ascii), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($words)) {
            return 'DM';
        }

        $letters = collect($words)
            ->filter(fn (string $word) => !in_array($word, ['VA', 'AND', 'THE'], true))
            ->map(fn (string $word) => $word[0])
            ->implode('');

        if ($letters === '') {
            $letters = Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $ascii), 0, 3));
        }

        return Str::substr($letters ?: 'DM', 0, 10);
    }

    public static function normalizeCategoryCode(?string $code): ?string
    {
        if (blank($code)) {
            return null;
        }

        $normalized = preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($code));

        return Str::upper(Str::substr($normalized, 0, 10));
    }

    public static function uniqueCategoryCode(string $name, ?int $ignoreCategoryId = null): string
    {
        $base = self::categoryCodeFromName($name);
        $code = $base;
        $suffix = 2;

        while (Category::query()
            ->when($ignoreCategoryId, fn ($query) => $query->whereKeyNot($ignoreCategoryId))
            ->where('code', $code)
            ->exists()) {
            $tail = (string) $suffix++;
            $code = Str::substr($base, 0, max(1, 10 - strlen($tail))) . $tail;
        }

        return $code;
    }

    public static function productCodeForCategory(Category $category): string
    {
        $prefix = self::normalizeCategoryCode($category->code)
            ?: self::categoryCodeFromName($category->name);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = $prefix . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);

            if (! Product::query()->where('product_code', $code)->exists()) {
                return $code;
            }
        }

        do {
            $code = $prefix . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        } while (Product::query()->where('product_code', $code)->exists());

        return $code;
    }
}
