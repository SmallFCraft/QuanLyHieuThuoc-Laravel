<?php

namespace App\Support;

use App\Models\DanhMuc;
use App\Models\SanPham;
use Illuminate\Support\Str;

class TaoMaSanPham
{
    public static function maDanhMucTuTen(string $name): string
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

    public static function chuanHoaMaDanhMuc(?string $code): ?string
    {
        if (blank($code)) {
            return null;
        }

        $normalized = preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($code));

        return Str::upper(Str::substr($normalized, 0, 10));
    }

    public static function maDanhMucDuyNhat(string $name, ?int $ignoreCategoryId = null): string
    {
        $base = self::maDanhMucTuTen($name);
        $code = $base;
        $suffix = 2;

        while (DanhMuc::query()
            ->when($ignoreCategoryId, fn ($query) => $query->whereKeyNot($ignoreCategoryId))
            ->where('ma_danh_muc', $code)
            ->exists()) {
            $tail = (string) $suffix++;
            $code = Str::substr($base, 0, max(1, 10 - strlen($tail))) . $tail;
        }

        return $code;
    }

    public static function maSanPhamTheoDanhMuc(DanhMuc $danhMuc): string
    {
        $prefix = self::chuanHoaMaDanhMuc($danhMuc->ma_danh_muc)
            ?: self::maDanhMucTuTen($danhMuc->ten);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = $prefix . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);

            if (! SanPham::query()->where('ma_san_pham', $code)->exists()) {
                return $code;
            }
        }

        do {
            $code = $prefix . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        } while (SanPham::query()->where('ma_san_pham', $code)->exists());

        return $code;
    }
}
