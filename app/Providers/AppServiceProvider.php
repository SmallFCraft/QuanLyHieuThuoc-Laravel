<?php

namespace App\Providers;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\TruyVanTimKiem;
use App\Observers\SanPhamObserver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Dang ky bat ky dich vu ung dung nao.
     */
    public function register(): void
    {
        //
    }

    /**
     * Khoi dong bat ky dich vu ung dung nao.
     */
    public function boot(): void
    {
        SanPham::observe(SanPhamObserver::class);

        View::composer(['layouts.app', 'layouts.guest'], function ($view) {
            // browsable(): dropdown truoc day liet ke moi danh muc, ke ca nhung
            // danh muc khong con ton kho — chung mo ra trang san pham trong.
            $view->with('navCategories', Cache::remember(
                DanhMuc::NAV_CACHE_KEY,
                now()->addMinutes(10),
                fn () => DanhMuc::query()
                    ->select('ma_dm', 'ten')
                    ->browsable()
                    ->get()
            ));

            $view->with('footerCategories', Cache::remember(
                DanhMuc::FOOTER_CACHE_KEY,
                now()->addMinutes(10),
                fn () => DanhMuc::query()
                    ->withSum('sanPhams', 'luot_xem')
                    ->orderByDesc('san_phams_sum_luot_xem')
                    ->limit(4)
                    ->get()
            ));

            // Du lieu cho panel tim kiem, nhung thang vao trang thay vi goi them mot
            // endpoint: panel mo la hien ngay, khong cho mang, va HTML da duoc Blade
            // escape. Ca ba khoi deu sau cache nen chi phi la 2 query moi 10 phut.
            $view->with('searchTrending', TruyVanTimKiem::trending());

            $view->with('searchPanelProducts', Cache::remember(
                DanhMuc::SEARCH_PANEL_PRODUCTS_CACHE_KEY,
                now()->addMinutes(10),
                fn () => SanPham::query()
                    ->select('ma_sp', 'ten', 'ma_dm', 'gia', 'hinh_anh')
                    ->notExpired()
                    ->where('so_luong_ton', '>', 0)
                    ->orderByDesc('luot_xem')
                    ->limit(3)
                    ->get()
            ));
        });
    }
}
