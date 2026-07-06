<?php

namespace App\Exports;

use App\Models\Order;
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
        return Order::query()
            ->with('user')
            ->when($this->status, fn ($query) => $query->where('status', $this->status))
            ->when($this->search, function ($query) {
                $search = $this->search;

                $query->where(function ($inner) use ($search) {
                    $inner->where('MaDH', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%"));
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

    public function map($order): array
    {
        return [
            '#' . $order->MaDH,
            $order->user->name ?? 'Khách hàng không tồn tại',
            (int) round((float) $order->total_amount),
            $this->statusLabel($order->status),
            optional($order->created_at)->format('d/m/Y H:i'),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'Pending' => 'Chờ xử lý',
            'Delivering' => 'Đang giao',
            'Completed' => 'Hoàn thành',
            'Cancelled' => 'Đã hủy',
            default => $status,
        };
    }
}
