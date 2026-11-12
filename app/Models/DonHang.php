<?php

namespace App\Models;

use App\Helpers\VietnameseHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class DonHang extends Model
{
    use HasFactory, Auditable;

    protected $table = 'orders';

    protected $primaryKey = 'MaDH';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'MaKH',
        'total_amount',
        'status',
        'shipping_address',
        'shipping_phone',
        'delivery_time',
        'order_notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    /**
     * Get the user that owns the order.
     */
    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'MaKH', 'MaKH');
    }

    /**
     * Get the order items for the order.
     */
    public function chiTietDonHangs()
    {
        return $this->hasMany(ChiTietDonHang::class, 'MaDH', 'MaDH');
    }

    /**
     * Get formatted total amount in Vietnamese currency format.
     *
     * @return string
     */
    public function getFormattedTotalAttribute(): string
    {
        return VietnameseHelper::formatCurrency($this->total_amount);
    }

    /**
     * Get formatted created date in Vietnamese format (dd/MM/yyyy).
     *
     * @return string
     */
    public function getFormattedCreatedAtAttribute(): string
    {
        return $this->created_at->format('d/m/Y H:i');
    }
}
