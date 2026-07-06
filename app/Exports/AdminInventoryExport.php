<?php

namespace App\Exports;

use App\Models\Product;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class AdminInventoryExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(
        private readonly ?string $status = null,
        private readonly int $days = 30,
        private readonly ?string $search = null,
    ) {
    }

    public function collection(): Collection
    {
        return Product::query()
            ->with('category')
            ->when($this->status === 'low_stock', fn ($query) => $query->lowStock())
            ->when($this->status === 'expiring', fn ($query) => $query->expiringSoon($this->days))
            ->when($this->status === 'expired', fn ($query) => $query->where('expiration_date', '<', now()))
            ->when($this->status === 'out_of_stock', fn ($query) => $query->where('stock_quantity', 0))
            ->when($this->search, function ($query) {
                $search = $this->search;

                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('batch_number', 'like', "%{$search}%")
                        ->orWhere('product_code', 'like', "%{$search}%")
                        ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->FEFO()
            ->get();
    }

    public function headings(): array
    {
        return [
            'Mã',
            'Tên sản phẩm',
            'Danh mục',
            'Số lô',
            'Tồn kho',
            'Điểm đặt lại',
            'Hạn sử dụng',
            'Trạng thái',
        ];
    }

    public function map($product): array
    {
        return [
            $product->display_code,
            $product->name,
            $product->category->name ?? 'Chưa phân loại',
            $product->batch_number,
            $product->stock_quantity,
            $product->reorder_point,
            optional($product->expiration_date)->format('d/m/Y'),
            $this->statusLabel($product),
        ];
    }

    private function statusLabel(Product $product): string
    {
        if ($product->isExpired()) {
            return 'Đã hết hạn';
        }

        if ($product->isExpiringSoon($this->days)) {
            return 'Sắp hết hạn';
        }

        if ($product->isLowStock()) {
            return 'Tồn thấp';
        }

        return 'Bình thường';
    }
}
