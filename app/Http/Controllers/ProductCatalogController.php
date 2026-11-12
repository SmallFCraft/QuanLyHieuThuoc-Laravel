<?php

namespace App\Http\Controllers;

use App\Models\SanPham;
use App\Models\DanhMuc;
use App\Models\TruyVanTimKiem;
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
        // Array query (?q[]=x, ?category[]=1) không phải do link nào trong app sinh, và
        // ép mảng sang chuỗi là TypeError (500). 400, không phải 404. Phải đứng trước
        // mọi phép đọc tham số.
        abort_if(
            is_array($request->query('q')) || is_array($request->query('category')),
            400
        );

        $searchTerm = trim((string) $request->input('q', ''));

        // where('ma_dm', ...) im lặng trả một danh sách rỗng 200 cho id không tồn tại,
        // nên mỗi số tự đặt là một URL indexable. byCategory() dùng findOrFail nên
        // chỉ cần chặn ở đây. ?category= có tác dụng, không phải junk query.
        if ($request->filled('category')) {
            abort_unless(
                DanhMuc::whereKey($request->input('category'))->exists(),
                404
            );
        }

        $query = SanPham::with('danhMuc')
            ->notExpired()
            ->where('so_luong_ton', '>', 0)
            ->storefrontSearch($searchTerm)
            ->orderBySearchRelevance($searchTerm);

        if ($request->filled('category')) {
            $query->where('ma_dm', $request->input('category'));
        }

        // Trang vượt quá trang cuối là 404, không phải một trang rỗng 200. Trả về
        // đúng paginator cho cả nhánh ajax bên dưới.
        $products = $this->paginateOr404($query, self::PER_PAGE);

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
        $product = SanPham::with('danhMuc')
            ->notExpired()
            ->findOrFail($MaSP);

        // Increment views count
        $product->increment('luot_xem');

        $relatedProducts = SanPham::with('danhMuc')
            ->notExpired()
            ->where('so_luong_ton', '>', 0)
            ->where('ma_dm', $product->ma_dm)
            ->where('ma_sp', '!=', $product->ma_sp)
            ->orderByDesc('luot_xem')
            ->limit(4)
            ->get();

        return view('products.show', compact('product', 'relatedProducts'));
    }

    /**
     * Display products by category.
     */
    public function byCategory(Request $request, $MaDM)
    {
        $category = DanhMuc::findOrFail($MaDM);

        $products = $this->paginateOr404(
            SanPham::with('danhMuc')
                ->notExpired()
                ->where('ma_dm', $MaDM)
                ->where('so_luong_ton', '>', 0),
            self::PER_PAGE
        );

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
        abort_if(is_array($request->query('q')), 400);

        $searchTerm = trim((string) $request->input('q', ''));

        $products = $this->paginateOr404(
            SanPham::with('danhMuc')
                ->notExpired()
                ->where('so_luong_ton', '>', 0)
                ->storefrontSearch($searchTerm)
                ->orderBySearchRelevance($searchTerm),
            self::PER_PAGE
        );

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

        TruyVanTimKiem::record($searchTerm);
    }

    /**
     * Chỉ khối kết quả, cho bộ lọc danh mục và phân trang chạy không tải lại trang.
     *
     * Trả về đúng partial mà trang đầy đủ render, nên hai đường không thể lệch nhau.
     * Không có JS thì các link vẫn là link thật và nhánh này không bao giờ chạy.
     */
    private function resultsFragment($sanPhams, string $searchTerm): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'html' => view('products.partials.results', [
                'products' => $sanPhams,
                'searchTerm' => $searchTerm,
            ])->render(),
            'total' => $sanPhams->total(),
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
        if (mb_strlen($term) < 2 || SanPham::searchWords($term) === []) {
            return response()->json(['products' => [], 'categories' => []]);
        }

        $sanPhams = SanPham::notExpired()
            ->where('so_luong_ton', '>', 0)
            ->storefrontSearch($term)
            ->orderBySearchRelevance($term)
            ->select('ma_sp', 'ten', 'ma_dm', 'gia', 'hinh_anh')
            ->with('danhMuc:ma_dm,ten')
            ->limit(8)
            ->get();

        $folded = VietnameseText::fold($term);

        // Filtered in PHP: category names need the same folding as products, and
        // there are only a few dozen of them behind a cache, so a query per
        // keystroke buys nothing.
        $categories = $this->getFilterCategories()
            ->filter(fn ($danhMuc) => str_contains(VietnameseText::fold($danhMuc->ten), $folded))
            ->take(3)
            ->values();

        return response()->json([
            'products' => $sanPhams->map(function (SanPham $sanPham) {
                return [
                    'ma_sp' => $sanPham->ma_sp,
                    'name' => $sanPham->ten,
                    'category' => $sanPham->danhMuc?->ten,
                    'price' => $sanPham->formatted_price,
                    'image_url' => $sanPham->image_url,
                    'url' => route('products.show', $sanPham->ma_sp),
                ];
            }),
            'categories' => $categories->map(function ($danhMuc) {
                return [
                    'id' => $danhMuc->ma_dm,
                    'name' => $danhMuc->ten,
                    'url' => route('products.index', ['category' => $danhMuc->ma_dm]),
                ];
            }),
        ]);
    }

    /**
     * Get cached categories for the public catalog filter.
     *
     * DanhMuc::scopeBrowsable() is shared with the header dropdown, so the sidebar
     * and the nav always list the same categories in the same order.
     */
    protected function getFilterCategories()
    {
        return Cache::remember(
            DanhMuc::FILTER_CACHE_KEY,
            now()->addMinutes(10),
            fn () => DanhMuc::query()
                ->select('ma_dm', 'ten')
                ->browsable()
                ->get()
        );
    }
}
