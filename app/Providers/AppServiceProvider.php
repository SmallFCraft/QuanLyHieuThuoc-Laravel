<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Product;
use App\Models\SearchQuery;
use App\Observers\ProductObserver;
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
        Product::observe(ProductObserver::class);

        View::composer(['layouts.app', 'layouts.guest'], function ($view) {
            // browsable(): the dropdown used to list every category, including any
            // with nothing in stock — those opened an empty product page.
            $view->with('navCategories', Cache::remember(
                Category::NAV_CACHE_KEY,
                now()->addMinutes(10),
                fn () => Category::query()
                    ->select('MaDM', 'name')
                    ->browsable()
                    ->get()
            ));

            $view->with('footerCategories', Cache::remember(
                Category::FOOTER_CACHE_KEY,
                now()->addMinutes(10),
                fn () => Category::query()
                    ->withSum('products', 'views_count')
                    ->orderByDesc('products_sum_views_count')
                    ->limit(4)
                    ->get()
            ));

            // Dữ liệu cho panel tìm kiếm, nhúng thẳng vào trang thay vì gọi thêm một
            // endpoint: panel mở là hiện ngay, không chờ mạng, và HTML đã được Blade
            // escape. Cả ba khối đều sau cache nên chi phí là 2 query mỗi 10 phút.
            $view->with('searchTrending', SearchQuery::trending());

            $view->with('searchPanelProducts', Cache::remember(
                Category::SEARCH_PANEL_PRODUCTS_CACHE_KEY,
                now()->addMinutes(10),
                fn () => Product::query()
                    ->select('MaSP', 'name', 'MaDM', 'price', 'image')
                    ->notExpired()
                    ->where('stock_quantity', '>', 0)
                    ->orderByDesc('views_count')
                    ->limit(3)
                    ->get()
            ));
        });
    }
}
