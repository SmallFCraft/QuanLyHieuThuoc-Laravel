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
            $searchTerm = trim($request->search);
            $query->where(function($q) use ($searchTerm) {
                $q->where('name', 'like', '%' . $searchTerm . '%')
                  ->orWhere('ingredients', 'like', '%' . $searchTerm . '%')
                  ->orWhere('usage', 'like', '%' . $searchTerm . '%');
            });
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

        // Increment views count
        $product->increment('views_count');

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
        $searchTerm = trim($request->input('q', ''));
        
        $query = Product::with('category')
            ->notExpired()
            ->where('stock_quantity', '>', 0);
            
        if (!empty($searchTerm)) {
            $query->where(function($q) use ($searchTerm) {
                // Ưu tiên tìm chuỗi chính xác trên các trường liên quan
                $q->where('name', 'like', '%' . $searchTerm . '%')
                  ->orWhere('ingredients', 'like', '%' . $searchTerm . '%')
                  ->orWhere('usage', 'like', '%' . $searchTerm . '%');
                  
                // Mở rộng bộ lọc: tách từ khóa và tìm kiếm chéo
                $words = explode(' ', mb_strtolower($searchTerm, 'UTF-8'));
                if (count($words) > 1) {
                    foreach ($words as $word) {
                        if (mb_strlen($word, 'UTF-8') >= 3) { // Chỉ tìm các từ khóa có nghĩa
                            $q->orWhere('name', 'like', '%' . $word . '%')
                              ->orWhere('usage', 'like', '%' . $word . '%');
                        }
                    }
                }
            });
        }
        
        $products = $query->paginate(12)->withQueryString();

        $categories = Category::withCount('products')->get();

        return view('products.index', compact('products', 'categories', 'searchTerm'));
    }

    /**
     * Get search suggestions for autocomplete.
     */
    public function suggestions(Request $request)
    {
        $query = $request->input('q', '');
        
        if (strlen($query) < 2) {
            return response()->json([]);
        }

        // Get product suggestions
        $products = Product::notExpired()
            ->where('stock_quantity', '>', 0)
            ->where(function($q) use ($query) {
                $q->where('name', 'like', '%' . $query . '%')
                  ->orWhere('ingredients', 'like', '%' . $query . '%');
            })
            ->select('id', 'name', 'category_id', 'price', 'image')
            ->with('category:id,name')
            ->limit(8)
            ->get();

        // Get category suggestions
        $categories = Category::where('name', 'like', '%' . $query . '%')
            ->select('id', 'name')
            ->limit(3)
            ->get();

        return response()->json([
            'products' => $products->map(function($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category->name,
                    'price' => $product->formatted_price,
                    'image_url' => $product->image_url,
                    'url' => route('products.show', $product->id),
                ];
            }),
            'categories' => $categories->map(function($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'url' => route('products.index', ['category' => $category->id]),
                ];
            }),
        ]);
    }
}
