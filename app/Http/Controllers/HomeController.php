<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
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
        // Get 6 featured products (not expired, in stock)
        $featuredProducts = Product::with('category')
            ->where('expiration_date', '>', now())
            ->where('stock_quantity', '>', 0)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->limit(6)
            ->get();

        $categories = Category::query()
            ->withCount([
                'products as available_products_count' => function ($query) {
                    $query->where('expiration_date', '>', now())
                        ->where('stock_quantity', '>', 0)
                        ->whereNull('deleted_at');
                },
            ])
            ->having('available_products_count', '>', 0)
            ->orderBy('name')
            ->limit(10)
            ->get();

        return view('home', compact('featuredProducts', 'categories'));
    }
}
