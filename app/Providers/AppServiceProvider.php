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
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        SanPham::observe(SanPhamObserver::class);

        View::composer(['layouts.app', 'layouts.guest'], function ($view) {
            // browsable(): the dropdown used to list every category, including any
            // with nothing in stock — those opened an empty product page.
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

            // Dữ liệu cho panel tìm kiếm, nhúng thẳng vào trang thay vì gọi thêm một
            // endpoint: panel mở là hiện ngay, không chờ mạng, và HTML đã được Blade
            // escape. Cả ba khối đều sau cache nên chi phí là 2 query mỗi 10 phút.
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
