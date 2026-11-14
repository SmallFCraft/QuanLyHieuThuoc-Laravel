<?php

namespace App\Services;

use App\Models\SanPham;
use App\Models\LoSanPham;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class TonKhoService
{
    /**
     * Dieu chinh so luong ton kho cho mot san pham.
     *
     * @param int $productId
     * @param int $quantity Duong de tang, am de giam
     * @param string $reason Ma ly do cho viec dieu chinh
     * @param int $userId Nguoi thuc hien dieu chinh
     * @return bool
     * @throws \Exception
     */
    public function dieuChinhTonKho(int $productId, int $quantity, string $reason, int $userId): bool
    {
        return DB::transaction(function () use ($productId, $quantity, $reason, $userId) {
            $sanPham = SanPham::lockForUpdate()->findOrFail($productId);

            $coCacLo = $sanPham->cacLo()->exists();
            if ($coCacLo) {
                $sanPham->cacLo()->orderBy('ngay_het_han')->orderBy('ma_lo')->lockForUpdate()->get();
                $soLuongCu = (int) $sanPham->cacLo()->sum('so_luong_ton');
            } else {
                $soLuongCu = (int) ($sanPham->getAttributes()['so_luong_ton'] ?? 0);
            }

            $soLuongMoi = $soLuongCu + $quantity;
            if ($soLuongMoi < 0) {
                throw new \Exception('Số lượng tồn kho không thể âm');
            }

            if ($coCacLo) {
                $this->apDungBienDoTonLo($sanPham, $quantity);
                $soLuongMoi = (int) $sanPham->cacLo()->sum('so_luong_ton');
                // Dong bo stock_quantity sang bang san_phams de dam bao tinh nhat quan
                DB::table('san_phams')
                    ->where('ma_sp', $sanPham->ma_sp)
                    ->update(['so_luong_ton' => $soLuongMoi, 'updated_at' => now()]);
            } else {
                $sanPham->setAttribute('so_luong_ton', $soLuongMoi);
                $sanPham->save();
            }

            app(NhatKyKiemToanService::class)->ghi(
                action: 'inventory_adjustment',
                modelType: SanPham::class,
                modelId: $productId,
                oldValues: ['so_luong_ton' => $soLuongCu],
                newValues: [
                    'so_luong_ton' => $soLuongMoi,
                    'adjustment' => $quantity,
                    'reason' => $reason
                ]
            );

            Log::info('Inventory adjusted', [
                'ma_sp' => $productId,
                'old_quantity' => $soLuongCu,
                'new_quantity' => $soLuongMoi,
                'adjustment' => $quantity,
                'reason' => $reason,
                'ma_nd' => $userId
            ]);

            return true;
        });
    }

    /**
     * Ap dung mot bien do ton kho tren cac lo san pham (FEFO khi giam).
     *
     * @throws \Exception
     */
    private function apDungBienDoTonLo(SanPham $sanPham, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        if ($delta > 0) {
            $soLo = $sanPham->getAttributes()['so_lo'] ?? null;
            $lo = null;
            if ($soLo !== null && $soLo !== '') {
                $lo = $sanPham->cacLo()
                    ->where('so_lo', $soLo)
                    ->first();
            }
            if (! $lo instanceof LoSanPham) {
                $lo = $sanPham->cacLo()->orderBy('ngay_het_han')->orderBy('ma_lo')->first();
            }
            if (! $lo instanceof LoSanPham) {
                throw new \Exception('Không tìm thấy lô sản phẩm để điều chỉnh tồn kho.');
            }
            $lo->so_luong_ton += $delta;
            $lo->save();

            return;
        }

        $conLai = abs($delta);
        foreach ($sanPham->cacLo()->orderBy('ngay_het_han')->orderBy('ma_lo')->get() as $lo) {
            if ($conLai <= 0) {
                break;
            }
            if ($lo->so_luong_ton <= 0) {
                continue;
            }
            $soLuongLay = min($lo->so_luong_ton, $conLai);
            $lo->so_luong_ton -= $soLuongLay;
            $lo->save();
            $conLai -= $soLuongLay;
        }
        if ($conLai > 0) {
            throw new \Exception('Số lượng tồn kho không thể âm');
        }
    }

    /**
     * Kiem tra cac san pham het han trong so ngay chi dinh.
     *
     * @param int $daysThreshold So ngay can kiem tra (mac dinh 30)
     * @return Collection
     */
    public function kiemTraHetHan(int $daysThreshold = 30): Collection
    {
        return SanPham::with('danhMuc')
            ->where('ngay_het_han', '<=', Carbon::now()->addDays($daysThreshold))
            ->where('ngay_het_han', '>', Carbon::now())
            ->orderBy('ngay_het_han', 'asc')
            ->get();
    }

    /**
     * Lay cac san pham ton kho thap (so luong <= diem dat lai hang).
     *
     * @return Collection
     */
    public function laySanPhamSapHet(): Collection
    {
        return SanPham::with('danhMuc')
            ->lowStock()
            ->orderBy('so_luong_ton', 'asc')
            ->get();
    }

    /**
     * Lay thong ke tong quan ton kho.
     *
     * Ten key khop voi nhung gi admin/inventory/index.blade.php doc. View quay ve
     * `?? 0`, nen doi ten tai day se hien bon the so 0 phia tren mot bang liet ke
     * dung nhung dong dang duoc dem, ma khong gi bao loi lon trao.
     *
     * @return array
     */
    public function layTongQuanTonKho(): array
    {
        return [
            'total_products' => SanPham::count(),
            'total_value' => SanPham::sum(DB::raw('ROUND(gia, 0) * so_luong_ton')),
            'low_stock' => SanPham::lowStock()->count(),
            'expiring_soon' => SanPham::expiringSoon(30)->count(),
            'expired' => SanPham::where('ngay_het_han', '<', Carbon::now())->count(),
            'out_of_stock' => SanPham::where('so_luong_ton', 0)->count(),
        ];
    }
}
