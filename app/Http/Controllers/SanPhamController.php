<?php

namespace App\Http\Controllers;

use App\Models\SanPham;
use App\Models\DanhMuc;
use App\Models\TruyVanTimKiem;
use App\Support\VietnameseText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SanPhamController extends Controller
{
    /** San pham moi trang tren catalog, search va trang danh muc — ba cho phai bang nhau. */
    private const PER_PAGE = 10;

    /**
     * Display the public product catalog.
     */
    public function index(Request $request)
    {
        // Array query (?q[]=x, ?category[]=1) khong phai do link nao trong app sinh, va
        // ep mang sang chuoi la TypeError (500). 400, khong phai 404. Phai dung truoc
        // moi phep doc tham so.
        abort_if(
            is_array($request->query('q')) || is_array($request->query('category')),
            400
        );

        $tuKhoa = trim((string) $request->input('q', ''));

        // where('ma_dm', ...) im lang tra mot danh sach rong 200 cho id khong ton tai,
        // nen moi so tu dat la mot URL indexable. byCategory() dung findOrFail nen
        // chi can chan o day. ?category= co tac dung, khong phai junk query.
        if ($request->filled('category')) {
            abort_unless(
                DanhMuc::whereKey($request->input('category'))->exists(),
                404
            );
        }

        $truyVan = SanPham::with('danhMuc')
            ->notExpired()
            ->where('so_luong_ton', '>', 0)
            ->storefrontSearch($tuKhoa)
            ->orderBySearchRelevance($tuKhoa);

        if ($request->filled('category')) {
            $truyVan->where('ma_dm', $request->input('category'));
        }

        // Trang vuot qua trang cuoi la 404, khong phai mot trang rong 200. Tra ve
        // dung paginator cho ca nhanh ajax ben duoi.
        $sanPhams = $this->paginateOr404($truyVan, self::PER_PAGE);

        $danhMucs = $this->getFilterCategories();

        if ($request->ajax()) {
            return $this->resultsFragment($sanPhams, $tuKhoa);
        }

        $this->recordSearch($request, $tuKhoa, $sanPhams->total());

        return view('products.index', compact('sanPhams', 'danhMucs', 'tuKhoa'));
    }

    /**
     * Display a single product detail page.
     */
    public function show($maSanPham)
    {
        $sanPham = SanPham::with('danhMuc')
            ->notExpired()
            ->findOrFail($maSanPham);

        // Increment views count
        $sanPham->increment('luot_xem');

        $sanPhamLienQuan = SanPham::with('danhMuc')
            ->notExpired()
            ->where('so_luong_ton', '>', 0)
            ->where('ma_dm', $sanPham->ma_dm)
            ->where('ma_sp', '!=', $sanPham->ma_sp)
            ->orderByDesc('luot_xem')
            ->limit(4)
            ->get();

        return view('products.show', compact('sanPham', 'sanPhamLienQuan'));
    }

    /**
     * Display products by category.
     */
    public function byCategory(Request $request, $maDanhMuc)
    {
        $danhMuc = DanhMuc::findOrFail($maDanhMuc);

        $sanPhams = $this->paginateOr404(
            SanPham::with('danhMuc')
                ->notExpired()
                ->where('ma_dm', $maDanhMuc)
                ->where('so_luong_ton', '>', 0),
            self::PER_PAGE
        );

        $danhMucs = $this->getFilterCategories();

        if ($request->ajax()) {
            return $this->resultsFragment($sanPhams, '');
        }

        return view('products.index', compact('sanPhams', 'danhMucs', 'danhMuc'));
    }

    /**
     * Search products by name or active ingredient.
     */
    public function search(Request $request)
    {
        abort_if(is_array($request->query('q')), 400);

        $tuKhoa = trim((string) $request->input('q', ''));

        $sanPhams = $this->paginateOr404(
            SanPham::with('danhMuc')
                ->notExpired()
                ->where('so_luong_ton', '>', 0)
                ->storefrontSearch($tuKhoa)
                ->orderBySearchRelevance($tuKhoa),
            self::PER_PAGE
        );

        $danhMucs = $this->getFilterCategories();

        if ($request->ajax()) {
            return $this->resultsFragment($sanPhams, $tuKhoa);
        }

        $this->recordSearch($request, $tuKhoa, $sanPhams->total());

        return view('products.index', compact('sanPhams', 'danhMucs', 'tuKhoa'));
    }

    /**
     * Dem mot luot tim cho khoi "Xu huong tim kiem".
     *
     * Chi dem luot tim dau tien co ket qua: sang trang 2 la cung mot y dinh, va mot
     * tu khoa khong ra san pham nao thi dua len trang chu chi dan nguoi sau vao mot
     * trang trong. Ghi sau khi view da dung xong nen mot loi o day khong lam sap
     * trang ket qua.
     */
    private function recordSearch(Request $request, string $tuKhoa, int $total): void
    {
        if ($tuKhoa === '' || $total < 1 || $request->has('page')) {
            return;
        }

        TruyVanTimKiem::record($tuKhoa);
    }

    /**
     * Chi khoi ket qua, cho bo loc danh muc va phan trang chay khong tai lai trang.
     *
     * Tra ve dung partial ma trang day du render, nen hai duong khong the lech nhau.
     * Khong co JS thi cac link van la link that va nhanh nay khong bao gio chay.
     */
    private function resultsFragment($sanPhams, string $tuKhoa): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'html' => view('products.partials.results', [
                'sanPhams' => $sanPhams,
                'tuKhoa' => $tuKhoa,
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

        // mb_strlen, not strlen: "d" is two bytes, so a byte-length gate let a
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
        $danhMucs = $this->getFilterCategories()
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
                    'url' => route('san-pham.show', $sanPham->ma_sp),
                ];
            }),
            'categories' => $danhMucs->map(function ($danhMuc) {
                return [
                    'id' => $danhMuc->ma_dm,
                    'name' => $danhMuc->ten,
                    'url' => route('san-pham.index', ['category' => $danhMuc->ma_dm]),
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
