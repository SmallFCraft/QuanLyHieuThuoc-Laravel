<?php

namespace App\Models;

use App\Helpers\VietnameseHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChiTietDonHang extends Model
{
    use HasFactory;

    protected $table = 'order_items';

    protected $primaryKey = 'MaCTDH';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'MaDH',
        'MaSP',
        'MaLo',
        'quantity',
        'unit_price',
        'batch_number',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
    ];

    /**
     * Get the order that owns the order item.
     */
    public function donHang()
    {
        return $this->belongsTo(DonHang::class, 'MaDH', 'MaDH');
    }

    /**
     * Get the product that owns the order item.
     */
    public function sanPham()
    {
        return $this->belongsTo(SanPham::class, 'MaSP', 'MaSP');
    }

    /**
     * Get the batch that owns the order item.
     */
    public function loSanPham()
    {
        return $this->belongsTo(LoSanPham::class, 'MaLo', 'MaLo');
    }

    /**
     * Get the subtotal for this order item.
     *
     * @return float
     */
    public function getSubtotalAttribute(): float
    {
        return $this->quantity * (int) round((float) $this->unit_price);
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
