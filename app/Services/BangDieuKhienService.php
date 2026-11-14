<?php

namespace App\Services;

use App\Models\DonHang;
use App\Models\SanPham;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BangDieuKhienService
{
    /**
     * Lay tong doanh thu trong thang hien tai
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
     * Lay so don hang nhom theo trang thai
     */
    public function laySoDonTheoTrangThai(): array
    {
        $soDon = DonHang::select('trang_thai', DB::raw('count(*) as count'))
            ->groupBy('trang_thai')
            ->pluck('count', 'trang_thai')
            ->toArray();

        // Dam bao moi trang thai deu co mat
        $cacTrangThai = ['Pending', 'Delivering', 'Completed', 'Cancelled'];
        $ketQua = [];
        foreach ($cacTrangThai as $trangThai) {
            $ketQua[$trangThai] = $soDon[$trangThai] ?? 0;
        }

        return $ketQua;
    }

    /**
     * Lay so san pham sap het han trong so ngay chi dinh
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
     * Lay so san pham ton kho thap
     */
    public function laySoSanPhamSapHet(): int
    {
        return SanPham::whereColumn('so_luong_ton', '<=', 'muc_canh_bao')
            ->count();
    }

    /**
     * Lay du lieu doanh thu theo ngay cho bieu do (N ngay qua)
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

        // Dien cac ngay thieu bang 0
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
     * Lay phan bo trang thai don hang cho bieu do tron
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
     * Lay san pham ban chay nhat (theo so luong ban)
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
