<?php

namespace App\Models;

use App\Support\TaoMaSanPham;
use App\Support\VietnameseText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class DanhMuc extends Model
{
    use HasFactory;

    protected $table = 'danh_mucs';

    protected $primaryKey = 'ma_dm';

    public const NAV_CACHE_KEY = 'layout.nav_categories';
    public const FOOTER_CACHE_KEY = 'layout.footer_categories';
    public const FILTER_CACHE_KEY = 'catalog.filter_categories';

    /**
     * San pham xem nhieu o panel tim kiem. Song o day, canh ba key kia, vi cung duoc
     * ProductObserver xoa qua flushCategoryCaches() — mot observer khong the biet
     * hang so nam tren service provider.
     */
    public const SEARCH_PANEL_PRODUCTS_CACHE_KEY = 'search.panel_products';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ten',
        'ten_khong_dau',
        'ma_danh_muc',
        'bieu_tuong',
        'mo_ta',
    ];

    protected static function booted(): void
    {
        static::saving(function (DanhMuc $danhMuc): void {
            $danhMuc->ma_danh_muc = TaoMaSanPham::chuanHoaMaDanhMuc($danhMuc->ma_danh_muc)
                ?: TaoMaSanPham::maDanhMucDuyNhat($danhMuc->ten, $danhMuc->exists ? $danhMuc->getKey() : null);

            // Kept in sync here rather than at the call sites so a category created by
            // the crawler, the admin form or a factory folds the same way.
            if ($danhMuc->isDirty('ten')) {
                $danhMuc->ten_khong_dau = VietnameseText::fold((string) $danhMuc->ten);
            }
        });

        static::saved(function (): void {
            static::flushCategoryCaches();
        });

        static::deleted(function (): void {
            static::flushCategoryCaches();
        });
    }

    /**
     * Categories a customer can actually browse: at least one product in stock and
     * unexpired, counted, fullest first.
     *
     * Shared by the header dropdown and the catalog filter so the two cannot disagree
     * on which categories exist or in what order. The crawl produces 50 of them, and
     * alphabetical order led with "Cham soc ca nhan" (2 products) while burying
     * "Dung cu y te" (263).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBrowsable($query)
    {
        $inStock = fn ($productQuery) => $productQuery->notExpired()->where('so_luong_ton', '>', 0);

        return $query
            ->whereHas('sanPhams', $inStock)
            ->withCount(['sanPhams as so_san_pham' => $inStock])
            ->orderByDesc('so_san_pham')
            ->orderBy('ten');
    }

    /**
     * Get the products for the category.
     */
    public function sanPhams()
    {
        return $this->hasMany(SanPham::class, 'ma_dm', 'ma_dm');
    }

    /**
     * Check if the category has any products.
     *
     * @return bool
     */
    public function hasProducts(): bool
    {
        return $this->sanPhams()->exists();
    }

    /**
     * Get the count of products in this category.
     *
     * @return int
     */
    public function getProductCountAttribute(): int
    {
        return $this->sanPhams()->count();
    }

    /**
     * Forget shared category caches used across public layouts and catalog pages.
     */
    public static function flushCategoryCaches(): void
    {
        Cache::forget(self::NAV_CACHE_KEY);
        Cache::forget(self::FOOTER_CACHE_KEY);
        Cache::forget(self::FILTER_CACHE_KEY);
        Cache::forget(self::SEARCH_PANEL_PRODUCTS_CACHE_KEY);
    }
}
