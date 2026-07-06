<?php

namespace App\Exports;

use App\Models\User;
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
        return User::query()
            ->when($this->search, function ($query) {
                $search = $this->search;

                $query->where(function ($inner) use ($search) {
                    $inner->where('MaKH', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('role', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%");
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

    public function map($user): array
    {
        return [
            '#' . $user->MaKH,
            $user->name,
            $user->email,
            $user->role,
            $user->status === 'active' ? 'Hoạt động' : 'Vô hiệu hóa',
            optional($user->created_at)->format('d/m/Y H:i'),
        ];
    }
}
