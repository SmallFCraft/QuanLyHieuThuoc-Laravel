<?php

namespace App\Models;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Favorite extends Model
{
    use HasFactory;

    protected $primaryKey = 'MaYT';

    protected $fillable = [
        'MaKH',
        'MaSP',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'MaKH', 'MaKH');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'MaSP', 'MaSP');
    }

    public static function idsForUser(User $user): array
    {
        return static::where('MaKH', $user->MaKH)
            ->pluck('MaSP')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    public static function profileItemsFor(User $user, int $perPage = 6): LengthAwarePaginator
    {
        return static::with(['product.category'])
            ->where('MaKH', $user->MaKH)
            ->whereHas('product')
            ->latest()
            ->paginate($perPage, ['*'], 'favorites_page');
    }
}
