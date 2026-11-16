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
     * Các thuộc tính cho phép gán hàng loạt.
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
     * Các thuộc tính nên ép kiểu.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'so_luong' => 'integer',
        'don_gia' => 'decimal:2',
    ];

    /**
     * Đơn hàng sở hữu dòng chi tiết này.
     */
    public function donHang()
    {
        return $this->belongsTo(DonHang::class, 'ma_dh', 'ma_dh');
    }

    /**
     * Sản phẩm của dòng chi tiết này.
     */
    public function sanPham()
    {
        return $this->belongsTo(SanPham::class, 'ma_sp', 'ma_sp');
    }

    /**
     * Lô sản phẩm của dòng chi tiết này.
     */
    public function loSanPham()
    {
        return $this->belongsTo(LoSanPham::class, 'ma_lo', 'ma_lo');
    }

    /**
     * Tiền hàng của dòng chi tiết đơn hàng này.
     *
     * @return float
     */
    public function getSubtotalAttribute(): float
    {
        return $this->so_luong * (int) round((float) $this->don_gia);
    }

    /**
     * Tiền hàng đã định dạng theo định dạng tiền tệ Việt Nam.
     *
     * @return string
     */
    public function getFormattedSubtotalAttribute(): string
    {
        return VietnameseHelper::formatCurrency($this->subtotal);
    }
}
