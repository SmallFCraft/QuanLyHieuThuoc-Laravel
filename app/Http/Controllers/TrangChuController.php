<?php

namespace App\Http\Controllers;

use App\Models\DanhMuc;
use App\Models\SanPham;
use Illuminate\View\View;

class TrangChuController extends Controller
{
    /**
     * Display the landing page
     *
     * @return View
     */
    public function index(): View
    {
        // San pham moi ra mat (theo ngay tao moi nhat)
        $sanPhamMoi = SanPham::with('danhMuc')
            ->where('ngay_het_han', '>', now())
            ->where('so_luong_ton', '>', 0)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->limit(8)
            ->get();

        // San pham noi bat (theo luot xem cao nhat)
        $sanPhamNoiBat = SanPham::with('danhMuc')
            ->where('ngay_het_han', '>', now())
            ->where('so_luong_ton', '>', 0)
            ->whereNull('deleted_at')
            ->orderBy('luot_xem', 'desc')
            ->limit(8)
            ->get();

        // So that cho dai thong ke o hero. Truoc day ghi cung "10k+" va "24/7"
        // trong khi kho chi co hon 3.000 san pham ban duoc va duoc si chi truc
        // 8:00-20:00 (xem messages.working_hours_desc).
        $soSanPhamBanDuoc = SanPham::notExpired()
            ->where('so_luong_ton', '>', 0)
            ->count();

        $soDanhMucBanDuoc = DanhMuc::browsable()->count();

        // browsable(): cung mot scope voi dropdown header va bo loc catalog, nen ba cho
        // khong the lech nhau ve thu tu. Xep theo ten thi trang chu dan bang danh muc 2
        // san pham trong khi dropdown ngay tren no dan bang danh muc 263 — xem docblock
        // cua scopeBrowsable().
        $danhMucs = DanhMuc::browsable()->limit(10)->get();

        return view('home', compact(
            'sanPhamMoi',
            'sanPhamNoiBat',
            'danhMucs',
            'soSanPhamBanDuoc',
            'soDanhMucBanDuoc'
        ));
    }
}
