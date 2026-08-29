<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * Display the landing page
     *
     * @return View
     */
    public function index(): View
    {
        // Sản phẩm mới ra mắt (theo ngày tạo mới nhất)
        $newProducts = Product::with('category')
            ->where('expiration_date', '>', now())
            ->where('stock_quantity', '>', 0)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->limit(8)
            ->get();

        // Sản phẩm nổi bật (theo lượt xem cao nhất)
        $featuredProducts = Product::with('category')
            ->where('expiration_date', '>', now())
            ->where('stock_quantity', '>', 0)
            ->whereNull('deleted_at')
            ->orderBy('views_count', 'desc')
            ->limit(8)
            ->get();

        $categories = Category::query()
            ->whereHas('products', function ($query) {
                $query->where('expiration_date', '>', now())
                    ->where('stock_quantity', '>', 0)
                    ->whereNull('deleted_at');
            })
            ->withCount([
                'products as available_products_count' => function ($query) {
                    $query->where('expiration_date', '>', now())
                        ->where('stock_quantity', '>', 0)
                        ->whereNull('deleted_at');
                },
            ])
            ->orderBy('name')
            ->limit(10)
            ->get();

        return view('home', compact('newProducts', 'featuredProducts', 'categories'));
    }
}
