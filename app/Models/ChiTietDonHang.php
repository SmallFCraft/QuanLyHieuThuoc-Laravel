<?php

namespace App\Models;

use App\Helpers\VietnameseHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChiTietDonHang extends Model
{
    use HasFactory;

    protected $table = 'chi_tiet_don_hangs';

    protected $primaryKey = 'ma_ctdh';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ma_dh',
        'ma_sp',
        'ma_lo',
        'so_luong',
        'don_gia',
        'so_lo',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'so_luong' => 'integer',
        'don_gia' => 'decimal:2',
    ];

    /**
     * Get the order that owns the order item.
     */
    public function donHang()
    {
        return $this->belongsTo(DonHang::class, 'ma_dh', 'ma_dh');
    }

    /**
     * Get the product that owns the order item.
     */
    public function sanPham()
    {
        return $this->belongsTo(SanPham::class, 'ma_sp', 'ma_sp');
    }

    /**
     * Get the batch that owns the order item.
     */
    public function loSanPham()
    {
        return $this->belongsTo(LoSanPham::class, 'ma_lo', 'ma_lo');
    }

    /**
     * Get the subtotal for this order item.
     *
     * @return float
     */
    public function getSubtotalAttribute(): float
    {
        return $this->so_luong * (int) round((float) $this->don_gia);
    }

    /**
     * Get formatted subtotal in Vietnamese currency format.
     *
     * @return string
     */
    public function getFormattedSubtotalAttribute(): string
    {
        return VietnameseHelper::formatCurrency($this->subtotal);
    }
}
