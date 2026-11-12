<?php

namespace App\Models;

use App\Helpers\VietnameseHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class DonHang extends Model
{
    use HasFactory, Auditable;

    protected $table = 'don_hangs';

    protected $primaryKey = 'ma_dh';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ma_nd',
        'tong_tien',
        'trang_thai',
        'dia_chi_giao_hang',
        'sdt_giao_hang',
        'thoi_gian_giao',
        'ghi_chu',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tong_tien' => 'decimal:2',
    ];

    /**
     * Get the user that owns the order.
     */
    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'ma_nd', 'ma_nd');
    }

    /**
     * Get the order items for the order.
     */
    public function chiTietDonHangs()
    {
        return $this->hasMany(ChiTietDonHang::class, 'ma_dh', 'ma_dh');
    }

    /**
     * Get formatted total amount in Vietnamese currency format.
     *
     * @return string
     */
    public function getFormattedTotalAttribute(): string
    {
        return VietnameseHelper::formatCurrency($this->tong_tien);
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
