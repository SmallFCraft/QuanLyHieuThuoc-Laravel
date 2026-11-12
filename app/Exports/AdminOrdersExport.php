<?php

namespace App\Exports;

use App\Helpers\VietnameseHelper;
use App\Models\DonHang;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class AdminOrdersExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
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
            ->when($this->status, fn ($query) => $query->where('status', $this->status))
            ->when($this->search, function ($query) {
                $search = $this->search;

                $query->where(function ($inner) use ($search) {
                    $inner->where('MaDH', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhereHas('nguoiDung', fn ($nguoiDungQuery) => $nguoiDungQuery->where('name', 'like', "%{$search}%"));
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
            '#' . $donHang->MaDH,
            $donHang->nguoiDung->name ?? 'Khách hàng không tồn tại',
            (int) round((float) $donHang->total_amount),
            VietnameseHelper::getOrderStatusText($donHang->status),
            optional($donHang->created_at)->format('d/m/Y H:i'),
        ];
    }
}
