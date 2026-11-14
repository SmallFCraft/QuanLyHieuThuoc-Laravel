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
     * Cac thuoc tinh cho phep gan hang loat.
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

            // Giu dong bo tai day thay vi tung noi goi, de danh muc tao boi crawler,
            // form quan tri hay factory deu duoc fold cung mot cach.
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
     * Cac danh muc khach thuc su co the duyet: co it nhat mot san pham con ton kho
     * va chua het han, dem so luong, nhieu nhat truoc.
     *
     * Chung bo dropdown tren header va bo loc catalog dung, de hai cho khong the lech
     * nhau ve danh muc nao ton tai hay thu tu nao. Crawler sinh ra 50 danh muc, va
     * thu tu alphabet dan dat "Cham soc ca nhan" (2 san pham) len dau trong khi
     * "Dung cu y te" (263) nam sau.
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
     * Cac san pham thuoc danh muc nay.
     */
    public function sanPhams()
    {
        return $this->hasMany(SanPham::class, 'ma_dm', 'ma_dm');
    }

    /**
     * Kiem tra danh muc co san pham nao khong.
     *
     * @return bool
     */
    public function hasProducts(): bool
    {
        return $this->sanPhams()->exists();
    }

    /**
     * So san pham trong danh muc nay.
     *
     * @return int
     */
    public function getProductCountAttribute(): int
    {
        return $this->sanPhams()->count();
    }

    /**
     * Xoa cac cache danh muc dung chung giua cac layout cong khai va trang catalog.
     */
    public static function flushCategoryCaches(): void
    {
        Cache::forget(self::NAV_CACHE_KEY);
        Cache::forget(self::FOOTER_CACHE_KEY);
        Cache::forget(self::FILTER_CACHE_KEY);
        Cache::forget(self::SEARCH_PANEL_PRODUCTS_CACHE_KEY);
    }
}
