<?php

namespace App\Services;

use App\Models\DonHang;
use App\Models\SanPham;
use App\Models\LoSanPham;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DonHangService
{
    /**
     * Cap nhat trang thai don hang kem kiem tra.
     *
     * @param int $orderId
     * @param string $newStatus
     * @param int $userId
     * @return DonHang
     * @throws \Exception
     */
    public function capNhatTrangThai(int $orderId, string $newStatus, int $userId): DonHang
    {
        return DB::transaction(function () use ($orderId, $newStatus, $userId) {
            $donHang = DonHang::lockForUpdate()->findOrFail($orderId);

            if (!$this->kiemTraChuyenTrangThai($donHang->trang_thai, $newStatus)) {
                throw new \Exception("Không thể chuyển trạng thái từ {$donHang->trang_thai} sang {$newStatus}.");
            }

            $trangThaiCu = $donHang->trang_thai;
            $donHang->trang_thai = $newStatus;
            $donHang->save();

            Log::info('DonHang status updated', [
                'ma_dh' => $orderId,
                'old_status' => $trangThaiCu,
                'new_status' => $newStatus,
                'ma_nd' => $userId
            ]);

            // Idempotent (updateOrCreate tren ma_dh + ma_sp), nen mot thay doi trang thai
            // bi phat lai khong the nhan doi lich uong thuoc cua benh nhan.
            if ($newStatus === 'Completed' && $trangThaiCu !== 'Completed') {
                app(NhacUongThuocService::class)->taoTuDonHang($donHang);
            }

            return $donHang;
        });
    }

    /**
     * Huy don hang va khoi phuc ton kho.
     *
     * @param int $orderId
     * @param int $userId
     * @return bool
     * @throws \Exception
     */
    public function huyDonHang(int $orderId, int $userId): bool
    {
        DB::beginTransaction();

        try {
            $donHang = DonHang::with('chiTietDonHangs')->lockForUpdate()->findOrFail($orderId);

            // Chi don hang dang Pending moi duoc huy
            if ($donHang->trang_thai !== 'Pending') {
                throw new \Exception('Chỉ có thể hủy đơn hàng ở trạng thái Pending.');
            }

            $cacMaSanPhamAnhHuong = [];

            // Khoi phuc ton kho cho moi item don hang (theo lo)
            foreach ($donHang->chiTietDonHangs as $item) {
                $cacMaSanPhamAnhHuong[] = $item->ma_sp;

                if ($item->ma_lo) {
                    // Khoi phuc vao lo cu the
                    $lo = LoSanPham::lockForUpdate()->find($item->ma_lo);
                    if ($lo) {
                        $lo->so_luong_ton += $item->so_luong;
                        $lo->save();
                    }
                } else {
                    // Khong co ma_lo: uu tien lo trung so lo (SanPhamObserver tao lo khi tao SP)
                    $lo = LoSanPham::query()
                        ->where('ma_sp', $item->ma_sp)
                        ->where('so_lo', $item->so_lo)
                        ->lockForUpdate()
                        ->first();
                    if ($lo) {
                        $lo->so_luong_ton += $item->so_luong;
                        $lo->save();
                    } else {
                        $sanPham = SanPham::lockForUpdate()->find($item->ma_sp);
                        if ($sanPham) {
                            $sanPham->so_luong_ton += $item->so_luong;
                            $sanPham->save();
                        }
                    }
                }
            }

            foreach (array_unique($cacMaSanPhamAnhHuong) as $productId) {
                $sanPham = SanPham::with('cacLo')->lockForUpdate()->find($productId);

                if (!$sanPham) {
                    continue;
                }

                $tonKhoCapNhat = $sanPham->cacLo->isNotEmpty()
                    ? (int) $sanPham->cacLo->sum('so_luong_ton')
                    : (int) $sanPham->so_luong_ton;

                $sanPham->so_luong_ton = $tonKhoCapNhat;
                $sanPham->save();
            }

            // Cap nhat trang thai don hang
            $donHang->trang_thai = 'Cancelled';
            $donHang->save();

            DB::commit();

            Log::info('DonHang cancelled and inventory restored', [
                'ma_dh' => $orderId,
                'ma_nd' => $userId
            ]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('DonHang cancellation failed', [
                'ma_dh' => $orderId,
                'ma_nd' => $userId,
                'exception' => $e->getMessage()
            ]);

            throw new \Exception('Không thể hủy đơn hàng. Vui lòng thử lại.');
        }
    }

    /**
     * Kiem tra chuyen trang thai co duoc phep hay khong.
     *
     * @param string $currentStatus
     * @param string $newStatus
     * @return bool
     */
    public function kiemTraChuyenTrangThai(string $currentStatus, string $newStatus): bool
    {
        $cacChuyenTrangThaiHopLe = [
            'Pending' => ['Delivering', 'Cancelled'],
            'Delivering' => ['Completed'],
            'Completed' => [],
            'Cancelled' => []
        ];

        return in_array($newStatus, $cacChuyenTrangThaiHopLe[$currentStatus] ?? []);
    }
}
