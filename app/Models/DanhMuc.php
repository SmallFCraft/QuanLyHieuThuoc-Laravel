<?php

namespace App\Models;

use App\Support\ProductCodeGenerator;
use App\Support\VietnameseText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class DanhMuc extends Model
{
    use HasFactory;

    protected $table = 'categories';

    protected $primaryKey = 'MaDM';

    public const NAV_CACHE_KEY = 'layout.nav_categories';
    public const FOOTER_CACHE_KEY = 'layout.footer_categories';
    public const FILTER_CACHE_KEY = 'catalog.filter_categories';

    /**
     * Sản phẩm xem nhiều ở panel tìm kiếm. Sống ở đây, cạnh ba key kia, vì cùng được
     * ProductObserver xoá qua flushCategoryCaches() — một observer không thể biết
     * hằng số nằm trên service provider.
     */
    public const SEARCH_PANEL_PRODUCTS_CACHE_KEY = 'search.panel_products';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'name_folded',
        'code',
        'icon',
        'description',
    ];

    protected static function booted(): void
    {
        static::saving(function (DanhMuc $danhMuc): void {
            $danhMuc->code = ProductCodeGenerator::normalizeCategoryCode($danhMuc->code)
                ?: ProductCodeGenerator::uniqueCategoryCode($danhMuc->name, $danhMuc->exists ? $danhMuc->getKey() : null);

            // Kept in sync here rather than at the call sites so a category created by
            // the crawler, the admin form or a factory folds the same way.
            if ($danhMuc->isDirty('name')) {
                $danhMuc->name_folded = VietnameseText::fold((string) $danhMuc->name);
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
     * alphabetical order led with "Chăm sóc cá nhân" (2 products) while burying
     * "Dụng cụ y tế" (263).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBrowsable($query)
    {
        $inStock = fn ($productQuery) => $productQuery->notExpired()->where('stock_quantity', '>', 0);

        return $query
            ->whereHas('sanPhams', $inStock)
            // Alias withCount sinh san_phams_count; Blade (layouts, catalog, home)
            // vẫn đọc products_count cho tới Task 7 nên giữ alias cũ qua as().
            ->withCount(['sanPhams as products_count' => $inStock])
            ->orderByDesc('products_count')
            ->orderBy('name');
    }

    /**
     * Get the products for the category.
     */
    public function sanPhams()
    {
        return $this->hasMany(SanPham::class, 'MaDM', 'MaDM');
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
