<?php

namespace App\Exports;

use App\Models\SanPham;
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
        return SanPham::query()
            ->with('danhMuc')
            ->inventoryStatus($this->status, $this->days)
            ->search($this->search)
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
            $product->danhMuc->name ?? 'Chưa phân loại',
            $product->batch_number,
            $product->stock_quantity,
            $product->reorder_point,
            optional($product->expiration_date)->format('d/m/Y'),
            $this->statusLabel($product),
        ];
    }

    private function statusLabel(SanPham $sanPham): string
    {
        if ($sanPham->isExpired()) {
            return 'Đã hết hạn';
        }

        if ($sanPham->isExpiringSoon($this->days)) {
            return 'Sắp hết hạn';
        }

        if ($sanPham->isLowStock()) {
            return 'Tồn thấp';
        }

        return 'Bình thường';
    }
}
