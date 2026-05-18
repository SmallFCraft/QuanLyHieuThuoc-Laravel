<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Category extends Model
{
    use HasFactory;

    protected $primaryKey = 'MaDM';

    public const NAV_CACHE_KEY = 'layout.nav_categories';
    public const FOOTER_CACHE_KEY = 'layout.footer_categories';
    public const FILTER_CACHE_KEY = 'catalog.filter_categories';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'icon',
        'description',
    ];

    protected static function booted(): void
    {
        static::saved(function (): void {
            static::flushCategoryCaches();
        });

        static::deleted(function (): void {
            static::flushCategoryCaches();
        });
    }

    /**
     * Get the products for the category.
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'MaDM', 'MaDM');
    }

    /**
     * Check if the category has any products.
     *
     * @return bool
     */
    public function hasProducts(): bool
    {
        return $this->products()->exists();
    }

    /**
     * Get the count of products in this category.
     *
     * @return int
     */
    public function getProductCountAttribute(): int
    {
        return $this->products()->count();
    }

    /**
     * Forget shared category caches used across public layouts and catalog pages.
     */
    public static function flushCategoryCaches(): void
    {
        Cache::forget(self::NAV_CACHE_KEY);
        Cache::forget(self::FOOTER_CACHE_KEY);
        Cache::forget(self::FILTER_CACHE_KEY);
    }
}
