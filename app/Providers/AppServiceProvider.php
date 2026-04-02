<?php

namespace App\Providers;

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
        // Register Product Observer
        \App\Models\Product::observe(\App\Observers\ProductObserver::class);
        
        \Illuminate\Support\Facades\View::composer('*', function ($view) {
            $view->with('navCategories', \App\Models\Category::all());
            $view->with('footerCategories', \App\Models\Category::query()
                ->withSum(['products' => function ($query) {
                    $query->whereNull('deleted_at');
                }], 'views_count')
                ->orderByDesc('products_sum_views_count')
                ->limit(4)
                ->get()
            );
        });
    }
}
