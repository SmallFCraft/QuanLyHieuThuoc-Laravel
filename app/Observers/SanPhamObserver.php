<?php

namespace App\Observers;

use App\Models\SanPham;
use App\Models\DanhMuc;
use App\Models\LoSanPham;

class SanPhamObserver
{
    /**
     * Handle the SanPham "created" event.
     */
    public function created(SanPham $sanPham): void
    {
        // Auto-create a batch when product is created
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
     * Handle the SanPham "updated" event.
     */
    public function updated(SanPham $sanPham): void
    {
        DanhMuc::flushCategoryCaches();
    }

    /**
     * Handle the SanPham "deleted" event.
     */
    public function deleted(SanPham $sanPham): void
    {
        DanhMuc::flushCategoryCaches();
    }

    /**
     * Handle the SanPham "restored" event.
     */
    public function restored(SanPham $sanPham): void
    {
        DanhMuc::flushCategoryCaches();
    }

    /**
     * Handle the SanPham "force deleted" event.
     */
    public function forceDeleted(SanPham $sanPham): void
    {
        DanhMuc::flushCategoryCaches();
    }
}
