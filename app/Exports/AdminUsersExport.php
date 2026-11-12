<?php

namespace App\Exports;

use App\Models\NguoiDung;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class AdminUsersExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(private readonly ?string $search = null)
    {
    }

    public function collection(): Collection
    {
        return NguoiDung::query()
            ->when($this->search, function ($query) {
                $search = $this->search;

                $query->where(function ($inner) use ($search) {
                    $inner->where('ma_nd', 'like', "%{$search}%")
                        ->orWhere('ho_ten', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('vai_tro', 'like', "%{$search}%")
                        ->orWhere('trang_thai', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('created_at')
            ->get();
    }

    public function headings(): array
    {
        return [
            'Mã',
            'Tên',
            'Email',
            'Vai trò',
            'Trạng thái',
            'Ngày tạo',
        ];
    }

    public function map($nguoiDung): array
    {
        return [
            '#' . $nguoiDung->ma_nd,
            $nguoiDung->ho_ten,
            $nguoiDung->email,
            $nguoiDung->vai_tro,
            $nguoiDung->trang_thai === 'active' ? 'Hoạt động' : 'Vô hiệu hóa',
            optional($nguoiDung->created_at)->format('d/m/Y H:i'),
        ];
    }
}
