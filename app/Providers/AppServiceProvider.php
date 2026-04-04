<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Product;
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
            $view->with('navCategories', Cache::remember(
                Category::NAV_CACHE_KEY,
                now()->addMinutes(10),
                fn () => Category::query()
                    ->orderBy('name')
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
        });
    }
}
