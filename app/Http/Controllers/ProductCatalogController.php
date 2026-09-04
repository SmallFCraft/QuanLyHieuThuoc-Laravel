<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use App\Models\SearchQuery;
use App\Support\VietnameseText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductCatalogController extends Controller
{
    /** Sản phẩm mỗi trang trên catalog, search và trang danh mục — ba chỗ phải bằng nhau. */
    private const PER_PAGE = 10;

    /**
     * Display the public product catalog.
     */
    public function index(Request $request)
    {
        $searchTerm = trim((string) $request->input('q', ''));

        $query = Product::with('category')
            ->notExpired()
            ->where('stock_quantity', '>', 0)
            ->storefrontSearch($searchTerm)
            ->orderBySearchRelevance($searchTerm);

        if ($request->filled('category')) {
            $query->where('MaDM', $request->input('category'));
        }

        $products = $query->paginate(self::PER_PAGE)->withQueryString();

        $categories = $this->getFilterCategories();

        if ($request->ajax()) {
            return $this->resultsFragment($products, $searchTerm);
        }

        $this->recordSearch($request, $searchTerm, $products->total());

        return view('products.index', compact('products', 'categories', 'searchTerm'));
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

        $relatedProducts = Product::with('category')
            ->notExpired()
            ->where('stock_quantity', '>', 0)
            ->where('MaDM', $product->MaDM)
            ->where('MaSP', '!=', $product->MaSP)
            ->orderByDesc('views_count')
            ->limit(4)
            ->get();

        return view('products.show', compact('product', 'relatedProducts'));
    }

    /**
     * Display products by category.
     */
    public function byCategory(Request $request, $MaDM)
    {
        $category = Category::findOrFail($MaDM);

        $products = Product::with('category')
            ->notExpired()
            ->where('MaDM', $MaDM)
            ->where('stock_quantity', '>', 0)
            ->paginate(self::PER_PAGE);

        $categories = $this->getFilterCategories();

        if ($request->ajax()) {
            return $this->resultsFragment($products, '');
        }

        return view('products.index', compact('products', 'categories', 'category'));
    }

    /**
     * Search products by name or active ingredient.
     */
    public function search(Request $request)
    {
        $searchTerm = trim((string) $request->input('q', ''));

        $products = Product::with('category')
            ->notExpired()
            ->where('stock_quantity', '>', 0)
            ->storefrontSearch($searchTerm)
            ->orderBySearchRelevance($searchTerm)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $categories = $this->getFilterCategories();

        if ($request->ajax()) {
            return $this->resultsFragment($products, $searchTerm);
        }

        $this->recordSearch($request, $searchTerm, $products->total());

        return view('products.index', compact('products', 'categories', 'searchTerm'));
    }

    /**
     * Đếm một lượt tìm cho khối "Xu hướng tìm kiếm".
     *
     * Chỉ đếm lượt tìm đầu tiên có kết quả: sang trang 2 là cùng một ý định, và một
     * từ khoá không ra sản phẩm nào thì đưa lên trang chủ chỉ dẫn người sau vào một
     * trang trống. Ghi sau khi view đã dựng xong nên một lỗi ở đây không làm sập
     * trang kết quả.
     */
    private function recordSearch(Request $request, string $searchTerm, int $total): void
    {
        if ($searchTerm === '' || $total < 1 || $request->has('page')) {
            return;
        }

        SearchQuery::record($searchTerm);
    }

    /**
     * Chỉ khối kết quả, cho bộ lọc danh mục và phân trang chạy không tải lại trang.
     *
     * Trả về đúng partial mà trang đầy đủ render, nên hai đường không thể lệch nhau.
     * Không có JS thì các link vẫn là link thật và nhánh này không bao giờ chạy.
     */
    private function resultsFragment($products, string $searchTerm): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'html' => view('products.partials.results', [
                'products' => $products,
                'searchTerm' => $searchTerm,
            ])->render(),
            'total' => $products->total(),
        ]);
    }

    /**
     * Get search suggestions for autocomplete.
     */
    public function suggestions(Request $request)
    {
        $term = trim((string) $request->input('q', ''));

        // mb_strlen, not strlen: "đ" is two bytes, so a byte-length gate let a
        // single-letter query through and matched most of the catalog.
        if (mb_strlen($term) < 2 || Product::searchWords($term) === []) {
            return response()->json(['products' => [], 'categories' => []]);
        }

        $products = Product::notExpired()
            ->where('stock_quantity', '>', 0)
            ->storefrontSearch($term)
            ->orderBySearchRelevance($term)
            ->select('MaSP', 'name', 'MaDM', 'price', 'image')
            ->with('category:MaDM,name')
            ->limit(8)
            ->get();

        $folded = VietnameseText::fold($term);

        // Filtered in PHP: category names need the same folding as products, and
        // there are only a few dozen of them behind a cache, so a query per
        // keystroke buys nothing.
        $categories = $this->getFilterCategories()
            ->filter(fn ($category) => str_contains(VietnameseText::fold($category->name), $folded))
            ->take(3)
            ->values();

        return response()->json([
            'products' => $products->map(function (Product $product) {
                return [
                    'MaSP' => $product->MaSP,
                    'name' => $product->name,
                    'category' => $product->category?->name,
                    'price' => $product->formatted_price,
                    'image_url' => $product->image_url,
                    'url' => route('products.show', $product->MaSP),
                ];
            }),
            'categories' => $categories->map(function ($category) {
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
     *
     * Category::scopeBrowsable() is shared with the header dropdown, so the sidebar
     * and the nav always list the same categories in the same order.
     */
    protected function getFilterCategories()
    {
        return Cache::remember(
            Category::FILTER_CACHE_KEY,
            now()->addMinutes(10),
            fn () => Category::query()
                ->select('MaDM', 'name')
                ->browsable()
                ->get()
        );
    }
}
