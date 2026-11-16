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
     * Sản phẩm xem nhiều ở panel tìm kiếm. Sống ở đây, cạnh ba key kia, vì cùng được
     * ProductObserver xoá qua flushCategoryCaches() — một observer không thể biết
     * hằng số nằm trên service provider.
     */
    public const SEARCH_PANEL_PRODUCTS_CACHE_KEY = 'search.panel_products';

    /**
     * Các thuộc tính cho phép gán hàng loạt.
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

            // Giữ đồng bộ tại đây thay vì từng nơi gọi, để danh mục tạo bởi crawler,
            // form quản trị hay factory đều được fold cùng một cách.
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
     * Các danh mục khách thực sự có thể duyệt: có ít nhất một sản phẩm còn tồn kho
     * và chưa hết hạn, đếm số lượng, nhiều nhất trước.
     *
     * Chung bởi dropdown trên header và bộ lọc catalog dùng, để hai chỗ không thể lệch
     * nhau về danh mục nào tồn tại hay thứ tự nào. Crawler sinh ra 50 danh mục, và
     * thứ tự alphabet dẫn đặt "Chăm sóc cá nhân" (2 sản phẩm) lên đầu trong khi
     * "Dụng cụ y tế" (263) nằm sau.
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
     * Các sản phẩm thuộc danh mục này.
     */
    public function sanPhams()
    {
        return $this->hasMany(SanPham::class, 'ma_dm', 'ma_dm');
    }

    /**
     * Kiểm tra danh mục có sản phẩm nào không.
     *
     * @return bool
     */
    public function hasProducts(): bool
    {
        return $this->sanPhams()->exists();
    }

    /**
     * Số sản phẩm trong danh mục này.
     *
     * @return int
     */
    public function getProductCountAttribute(): int
    {
        return $this->sanPhams()->count();
    }

    /**
     * Xoá các cache danh mục dùng chung giữa các layout công khai và trang catalog.
     */
    public static function flushCategoryCaches(): void
    {
        Cache::forget(self::NAV_CACHE_KEY);
        Cache::forget(self::FOOTER_CACHE_KEY);
        Cache::forget(self::FILTER_CACHE_KEY);
        Cache::forget(self::SEARCH_PANEL_PRODUCTS_CACHE_KEY);
    }
}
