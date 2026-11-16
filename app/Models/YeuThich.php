<?php

namespace App\Models;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class YeuThich extends Model
{
    use HasFactory;

    protected $table = 'yeu_thiches';

    protected $primaryKey = 'ma_yt';

    protected $fillable = [
        'ma_nd',
        'ma_sp',
    ];

    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'ma_nd', 'ma_nd');
    }

    public function sanPham()
    {
        return $this->belongsTo(SanPham::class, 'ma_sp', 'ma_sp');
    }

    public static function idsForUser(NguoiDung $nguoiDung): array
    {
        return static::where('ma_nd', $nguoiDung->ma_nd)
            ->pluck('ma_sp')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    public static function profileItemsFor(NguoiDung $nguoiDung, int $perPage = 6): LengthAwarePaginator
    {
        return static::with(['sanPham.danhMuc'])
            ->where('ma_nd', $nguoiDung->ma_nd)
            ->whereHas('sanPham')
            ->latest()
            ->paginate($perPage, ['*'], 'favorites_page');
    }
}
