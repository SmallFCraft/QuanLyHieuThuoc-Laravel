<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
            $query->where('MaDM', $request->category);
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

        $categories = $this->getFilterCategories();

        return view('products.index', compact('products', 'categories'));
    }

    /**
     * Display a single product detail page.
     */
    public function show($MaSP)
    {
        $product = Product::with('category')
            ->notExpired()
            ->findOrFail($MaSP);

        // Increment views count
        $product->increment('views_count');

        return view('products.show', compact('product'));
    }

    /**
     * Display products by category.
     */
    public function byCategory($MaDM)
    {
        $category = Category::findOrFail($MaDM);
        
        $products = Product::with('category')
            ->notExpired()
            ->where('MaDM', $MaDM)
            ->where('stock_quantity', '>', 0)
            ->paginate(12);

        $categories = $this->getFilterCategories();

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

        $categories = $this->getFilterCategories();

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
            ->select('MaSP', 'name', 'MaDM', 'price', 'image')
            ->with('category:MaDM,name')
            ->limit(8)
            ->get();

        // Get category suggestions
        $categories = Category::where('name', 'like', '%' . $query . '%')
            ->select('MaDM', 'name')
            ->limit(3)
            ->get();

        return response()->json([
            'products' => $products->map(function($product) {
                return [
                    'MaSP' => $product->MaSP,
                    'name' => $product->name,
                    'category' => $product->category->name,
                    'price' => $product->formatted_price,
                    'image_url' => $product->image_url,
                    'url' => route('products.show', $product->MaSP),
                ];
            }),
            'categories' => $categories->map(function($category) {
                return [
                    'id' => $category->MaDM,
                    'name' => $category->name,
                    'url' => route('products.index', ['category' => $category->MaDM]),
                ];
            }),
        ]);
    }

    /**
     * Get cached categories for the public catalog filter.
     */
    protected function getFilterCategories()
    {
        return Cache::remember(
            Category::FILTER_CACHE_KEY,
            now()->addMinutes(10),
            fn () => Category::query()
                ->select('MaDM', 'name', 'icon')
                ->whereHas('products', function ($query) {
                    $query->notExpired()->where('stock_quantity', '>', 0);
                })
                ->withCount([
                    'products' => function ($query) {
                        $query->notExpired()->where('stock_quantity', '>', 0);
                    },
                ])
                ->orderBy('name')
                ->get()
        );
    }
}
