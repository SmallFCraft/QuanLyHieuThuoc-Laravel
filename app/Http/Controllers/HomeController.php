<?php

namespace App\Http\Controllers;

use App\Models\DanhMuc;
use App\Models\SanPham;
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
        $newProducts = SanPham::with('danhMuc')
            ->where('expiration_date', '>', now())
            ->where('stock_quantity', '>', 0)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->limit(8)
            ->get();

        // Sản phẩm nổi bật (theo lượt xem cao nhất)
        $featuredProducts = SanPham::with('danhMuc')
            ->where('expiration_date', '>', now())
            ->where('stock_quantity', '>', 0)
            ->whereNull('deleted_at')
            ->orderBy('views_count', 'desc')
            ->limit(8)
            ->get();

        // Số thật cho dải thống kê ở hero. Trước đây ghi cứng "10k+" và "24/7"
        // trong khi kho chỉ có hơn 3.000 sản phẩm bán được và dược sĩ chỉ trực
        // 8:00-20:00 (xem messages.working_hours_desc).
        $sellableProductCount = SanPham::notExpired()
            ->where('stock_quantity', '>', 0)
            ->count();

        $sellableCategoryCount = DanhMuc::browsable()->count();

        // browsable(): cùng một scope với dropdown header và bộ lọc catalog, nên ba chỗ
        // không thể lệch nhau về thứ tự. Xếp theo tên thì trang chủ dẫn bằng danh mục 2
        // sản phẩm trong khi dropdown ngay trên nó dẫn bằng danh mục 263 — xem docblock
        // của scopeBrowsable().
        $categories = DanhMuc::browsable()->limit(10)->get();

        return view('home', compact(
            'newProducts',
            'featuredProducts',
            'categories',
            'sellableProductCount',
            'sellableCategoryCount'
        ));
    }
}
