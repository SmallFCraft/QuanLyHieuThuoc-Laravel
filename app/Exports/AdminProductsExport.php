<?php

namespace App\Exports;

use App\Models\Product;
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
        return Product::query()
            ->with('category')
            ->when($this->categoryId, fn ($query) => $query->where('MaDM', $this->categoryId))
            ->when($this->search, function ($query) {
                $search = $this->search;

                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('batch_number', 'like', "%{$search}%")
                        ->orWhere('product_code', 'like', "%{$search}%")
                        ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('MaSP')
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
            $product->name,
            $product->category->name ?? 'Chưa phân loại',
            $product->price_vnd,
            $product->stock_quantity,
            optional($product->expiration_date)->format('d/m/Y'),
            $product->batch_number,
        ];
    }
}
