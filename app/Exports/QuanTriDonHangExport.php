<?php

namespace App\Exports;

use App\Helpers\VietnameseHelper;
use App\Models\DonHang;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class QuanTriDonHangExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(
        private readonly ?string $status = null,
        private readonly ?string $search = null,
    ) {
    }

    public function collection(): Collection
    {
        return DonHang::query()
            ->with('nguoiDung')
            ->when($this->status, fn ($query) => $query->where('trang_thai', $this->status))
            ->when($this->search, function ($query) {
                $search = $this->search;

                $query->where(function ($inner) use ($search) {
                    $inner->where('ma_dh', 'like', "%{$search}%")
                        ->orWhere('trang_thai', 'like', "%{$search}%")
                        ->orWhereHas('nguoiDung', fn ($nguoiDungQuery) => $nguoiDungQuery->where('ho_ten', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('created_at')
            ->get();
    }

    public function headings(): array
    {
        return [
            'Mã đơn',
            'Khách hàng',
            'Tổng tiền',
            'Trạng thái',
            'Ngày đặt',
        ];
    }

    public function map($donHang): array
    {
        return [
            '#' . $donHang->ma_dh,
            $donHang->nguoiDung->ho_ten ?? 'Khách hàng không tồn tại',
            (int) round((float) $donHang->tong_tien),
            VietnameseHelper::getOrderStatusText($donHang->trang_thai),
            optional($donHang->created_at)->format('d/m/Y H:i'),
        ];
    }
}
