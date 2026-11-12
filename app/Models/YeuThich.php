<?php

namespace App\Models;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class YeuThich extends Model
{
    use HasFactory;

    protected $table = 'favorites';

    protected $primaryKey = 'MaYT';

    protected $fillable = [
        'MaKH',
        'MaSP',
    ];

    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'MaKH', 'MaKH');
    }

    public function sanPham()
    {
        return $this->belongsTo(SanPham::class, 'MaSP', 'MaSP');
    }

    public static function idsForUser(NguoiDung $nguoiDung): array
    {
        return static::where('MaKH', $nguoiDung->MaKH)
            ->pluck('MaSP')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    public static function profileItemsFor(NguoiDung $nguoiDung, int $perPage = 6): LengthAwarePaginator
    {
        return static::with(['sanPham.danhMuc'])
            ->where('MaKH', $nguoiDung->MaKH)
            ->whereHas('sanPham')
            ->latest()
            ->paginate($perPage, ['*'], 'favorites_page');
    }
}
