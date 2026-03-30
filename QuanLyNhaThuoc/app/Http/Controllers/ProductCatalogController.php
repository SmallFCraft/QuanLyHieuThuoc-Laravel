<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;

class ProductCatalogController extends Controller
{
    /**
     * Display the public product catalog.
     */
    public function index(Request $request)
    {
        $query = Product::with('category')
            ->notExpired()
            ->where('stock_quantity', '>', 0);

        // Apply category filter if provided
        if ($request->has('category') && $request->category) {
            $query->where('category_id', $request->category);
        }

        // Apply search filter if provided
        if ($request->has('search') && $request->search) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // Paginate results (12 per page)
        $products = $query->paginate(12);

        // Get all categories for filter sidebar
        $categories = Category::withCount('products')->get();

        return view('products.index', compact('products', 'categories'));
    }

    /**
     * Display a single product detail page.
     */
    public function show($id)
    {
        $product = Product::with('category')
            ->notExpired()
            ->findOrFail($id);

        return view('products.show', compact('product'));
    }

    /**
     * Display products by category.
     */
    public function byCategory($categoryId)
    {
        $category = Category::findOrFail($categoryId);
        
        $products = Product::with('category')
            ->notExpired()
            ->where('category_id', $categoryId)
            ->where('stock_quantity', '>', 0)
            ->paginate(12);

        $categories = Category::withCount('products')->get();

        return view('products.index', compact('products', 'categories', 'category'));
    }

    /**
     * Search products by name.
     */
    public function search(Request $request)
    {
        $searchTerm = $request->input('q', '');
        
        $products = Product::with('category')
            ->notExpired()
            ->where('name', 'like', '%' . $searchTerm . '%')
            ->paginate(12);

        $categories = Category::withCount('products')->get();

        return view('products.index', compact('products', 'categories', 'searchTerm'));
    }
}
