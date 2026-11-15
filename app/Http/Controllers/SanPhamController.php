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
    /** Sản phẩm mỗi trang trên catalog, search và trang danh mục — ba chỗ phải bằng nhau. */
    private const PER_PAGE = 10;

    /**
     * Hiển thị catalog sản phẩm công khai.
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

        $tuKhoa = trim((string) $request->input('q', ''));

        // where('ma_dm', ...) im lặng trả một danh sách rỗng 200 cho id không tồn tại,
        // nên mỗi số tự đặt là một URL indexable. byCategory() dùng findOrFail nên
        // chỉ cần chặn ở đây. ?category= có tác dụng, không phải junk query.
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

        // Trang vượt qua trang cuối là 404, không phải một trang rỗng 200. Trả về
        // đúng paginator cho cả nhánh ajax bên dưới.
        $sanPhams = $this->paginateOr404($truyVan, self::PER_PAGE);

        $danhMucs = $this->getFilterCategories();

        if ($request->ajax()) {
            return $this->resultsFragment($sanPhams, $tuKhoa);
        }

        $this->recordSearch($request, $tuKhoa, $sanPhams->total());

        return view('products.index', compact('sanPhams', 'danhMucs', 'tuKhoa'));
    }

    /**
     * Hiển thị trang chi tiết một sản phẩm.
     */
    public function show($maSanPham)
    {
        $sanPham = SanPham::with('danhMuc')
            ->notExpired()
            ->findOrFail($maSanPham);

        // Tăng lượt xem
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
     * Hiển thị sản phẩm theo danh mục.
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
     * Tìm kiếm sản phẩm theo tên hoặc hoạt chất.
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
     * Đếm một lượt tìm cho khối "Xu hướng tìm kiếm".
     *
     * Chỉ đếm lượt tìm đầu tiên có kết quả: sang trang 2 là cùng một ý định, và một
     * từ khoá không ra sản phẩm nào thì đưa lên trang chủ chỉ dẫn người sau vào một
     * trang trống. Ghi sau khi view đã dựng xong nên một lỗi ở đây không làm sập
     * trang kết quả.
     */
    private function recordSearch(Request $request, string $tuKhoa, int $total): void
    {
        if ($tuKhoa === '' || $total < 1 || $request->has('page')) {
            return;
        }

        TruyVanTimKiem::record($tuKhoa);
    }

    /**
     * Chỉ khối kết quả, cho bộ lọc danh mục và phân trang chạy không tải lại trang.
     *
     * Trả về đúng partial mà trang đầy đủ render, nên hai đường không thể lệch nhau.
     * Không có JS thì các link vẫn là link thật và nhánh này không bao giờ chạy.
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
     * Lấy gợi ý tìm kiếm cho autocomplete.
     */
    public function suggestions(Request $request)
    {
        $term = trim((string) $request->input('q', ''));

        // mb_strlen chứ không phải strlen: "đ" là hai byte, nên gate theo độ dài byte
        // đã để một truy vấn một ký tự đi qua và khớp gần cả catalog.
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

        // Lọc trong PHP: tên danh mục cần cùng cách fold với sản phẩm, và chỉ có vài
        // chục danh mục phía sau một cache, nên một truy vấn mỗi lần gõ phím không
        // mua được gì.
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
     * Lấy danh mục từ cache cho bộ lọc catalog công khai.
     *
     * DanhMuc::scopeBrowsable() dùng chung với dropdown trên header, nên sidebar
     * và nav luôn liệt kê cùng các danh mục cùng thứ tự.
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
