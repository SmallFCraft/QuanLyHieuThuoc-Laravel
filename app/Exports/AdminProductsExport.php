<?php

namespace App\Exports;

use App\Models\SanPham;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class AdminProductsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(
        private readonly ?string $categoryId = null,
        private readonly ?string $search = null,
    ) {
    }

    public function collection(): Collection
    {
        return SanPham::query()
            ->with('danhMuc')
            ->when($this->categoryId, fn ($query) => $query->where('ma_dm', $this->categoryId))
            ->search($this->search)
            ->orderByDesc('ma_sp')
            ->get();
    }

    public function headings(): array
    {
        return [
            'Mã',
            'Tên sản phẩm',
            'Danh mục',
            'Giá bán',
            'Tồn kho',
            'Hạn dùng',
            'Số lô',
        ];
    }

    public function map($product): array
    {
        return [
            $product->display_code,
            $product->ten,
            $product->danhMuc->ten ?? 'Chưa phân loại',
            $product->price_vnd,
            $product->so_luong_ton,
            optional($product->ngay_het_han)->format('d/m/Y'),
            $product->so_lo,
        ];
    }
}
