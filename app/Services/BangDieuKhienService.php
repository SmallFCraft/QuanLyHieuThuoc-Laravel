<?php

namespace App\Services;

use App\Models\DonHang;
use App\Models\SanPham;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BangDieuKhienService
{
    /**
     * Lấy tổng doanh thu trong tháng hiện tại
     */
    public function layDoanhThuThang(): float
    {
        $dauThang = Carbon::now()->startOfMonth();
        $cuoiThang = Carbon::now()->endOfMonth();

        return DonHang::whereBetween('created_at', [$dauThang, $cuoiThang])
            ->whereIn('trang_thai', ['Delivering', 'Completed'])
            ->sum('tong_tien');
    }

    /**
     * Lấy số đơn hàng nhóm theo trạng thái
     */
    public function laySoDonTheoTrangThai(): array
    {
        $soDon = DonHang::select('trang_thai', DB::raw('count(*) as count'))
            ->groupBy('trang_thai')
            ->pluck('count', 'trang_thai')
            ->toArray();

        // Đảm bảo mỗi trạng thái đều có mặt
        $cacTrangThai = ['Pending', 'Delivering', 'Completed', 'Cancelled'];
        $ketQua = [];
        foreach ($cacTrangThai as $trangThai) {
            $ketQua[$trangThai] = $soDon[$trangThai] ?? 0;
        }

        return $ketQua;
    }

    /**
     * Lấy số sản phẩm sắp hết hạn trong số ngày chỉ định
     */
    public function laySoSanPhamSapHetHan(int $soNgay = 30): int
    {
        $homNay = Carbon::today();
        $ngayTuongLai = Carbon::today()->addDays($soNgay);

        return SanPham::whereBetween('ngay_het_han', [$homNay, $ngayTuongLai])
            ->where('so_luong_ton', '>', 0)
            ->count();
    }

    /**
     * Lấy số sản phẩm tồn kho thấp
     */
    public function laySoSanPhamSapHet(): int
    {
        return SanPham::whereColumn('so_luong_ton', '<=', 'muc_canh_bao')
            ->count();
    }

    /**
     * Lấy dữ liệu doanh thu theo ngày cho biểu đồ (N ngày qua)
     */
    public function layBieuDoDoanhThuNgay(int $soNgay = 30): array
    {
        $ngayBatDau = Carbon::today()->subDays($soNgay - 1)->startOfDay();
        $ngayKetThuc = Carbon::today()->endOfDay();

        $doanhThuTheoNgay = DonHang::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(tong_tien) as revenue')
            )
            ->whereBetween('created_at', [$ngayBatDau, $ngayKetThuc])
            ->whereIn('trang_thai', ['Delivering', 'Completed'])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('revenue', 'date')
            ->toArray();

        // Điền các ngày thiếu bằng 0
        $nhan = [];
        $duLieu = [];
        for ($i = $soNgay - 1; $i >= 0; $i--) {
            $ngay = Carbon::today()->subDays($i)->format('Y-m-d');
            $nhan[] = Carbon::parse($ngay)->format('d/m');
            $duLieu[] = $doanhThuTheoNgay[$ngay] ?? 0;
        }

        return [
            'labels' => $nhan,
            'data' => $duLieu
        ];
    }

    /**
     * Lấy phân bố trạng thái đơn hàng cho biểu đồ tròn
     */
    public function layPhanBoTrangThaiDon(): array
    {
        $soDon = $this->laySoDonTheoTrangThai();

        return [
            'labels' => array_keys($soDon),
            'data' => array_values($soDon)
        ];
    }

    /**
     * Lấy sản phẩm bán chạy nhất (theo số lượng bán)
     */
    public function laySanPhamBanChay(int $gioiHan = 10): array
    {
        $sanPhamBanChay = DB::table('chi_tiet_don_hangs')
            ->join('don_hangs', 'chi_tiet_don_hangs.ma_dh', '=', 'don_hangs.ma_dh')
            ->join('san_phams', 'chi_tiet_don_hangs.ma_sp', '=', 'san_phams.ma_sp')
            ->whereIn('don_hangs.trang_thai', ['Delivering', 'Completed'])
            ->select(
                'san_phams.ten',
                DB::raw('SUM(chi_tiet_don_hangs.so_luong) as total_quantity')
            )
            ->groupBy('san_phams.ma_sp', 'san_phams.ten')
            ->orderByDesc('total_quantity')
            ->limit($gioiHan)
            ->get();

        return [
            'labels' => $sanPhamBanChay->pluck('ten')->toArray(),
            'data' => $sanPhamBanChay->pluck('total_quantity')->toArray()
        ];
    }
}
