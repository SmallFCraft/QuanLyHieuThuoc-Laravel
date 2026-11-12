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
        if ($sanPham->batch_number && $sanPham->expiration_date) {
            LoSanPham::create([
                'MaSP' => $sanPham->MaSP,
                'batch_number' => $sanPham->batch_number,
                'expiration_date' => $sanPham->expiration_date,
                'stock_quantity' => $sanPham->stock_quantity ?? 0,
                'reorder_point' => $sanPham->reorder_point ?? 10,
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
