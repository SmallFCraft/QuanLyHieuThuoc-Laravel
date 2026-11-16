<?php

namespace App\Observers;

use App\Models\SanPham;
use App\Models\DanhMuc;
use App\Models\LoSanPham;

class SanPhamObserver
{
    /**
     * Xử lý sự kiện "created" của SanPham.
     */
    public function created(SanPham $sanPham): void
    {
        // Tự tạo một lô khi sản phẩm được tạo
        if ($sanPham->so_lo && $sanPham->ngay_het_han) {
            LoSanPham::create([
                'ma_sp' => $sanPham->ma_sp,
                'so_lo' => $sanPham->so_lo,
                'ngay_het_han' => $sanPham->ngay_het_han,
                'so_luong_ton' => $sanPham->so_luong_ton ?? 0,
                'muc_canh_bao' => $sanPham->muc_canh_bao ?? 10,
            ]);
        }

        DanhMuc::flushCategoryCaches();
    }

    /**
     * Xử lý sự kiện "updated" của SanPham.
     */
    public function updated(SanPham $sanPham): void
    {
        DanhMuc::flushCategoryCaches();
    }

    /**
     * Xử lý sự kiện "deleted" của SanPham.
     */
    public function deleted(SanPham $sanPham): void
    {
        DanhMuc::flushCategoryCaches();
    }

    /**
     * Xử lý sự kiện "restored" của SanPham.
     */
    public function restored(SanPham $sanPham): void
    {
        DanhMuc::flushCategoryCaches();
    }

    /**
     * Xử lý sự kiện "force deleted" của SanPham.
     */
    public function forceDeleted(SanPham $sanPham): void
    {
        DanhMuc::flushCategoryCaches();
    }
}
