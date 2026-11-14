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
     * Cac thuoc tinh cho phep gan hang loat.
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
     * Cac thuoc tinh nen ep kieu.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'so_luong' => 'integer',
        'don_gia' => 'decimal:2',
    ];

    /**
     * Don hang so huu dong chi tiet nay.
     */
    public function donHang()
    {
        return $this->belongsTo(DonHang::class, 'ma_dh', 'ma_dh');
    }

    /**
     * San pham cua dong chi tiet nay.
     */
    public function sanPham()
    {
        return $this->belongsTo(SanPham::class, 'ma_sp', 'ma_sp');
    }

    /**
     * Lo san pham cua dong chi tiet nay.
     */
    public function loSanPham()
    {
        return $this->belongsTo(LoSanPham::class, 'ma_lo', 'ma_lo');
    }

    /**
     * Tien hang cua dong chi tiet don hang nay.
     *
     * @return float
     */
    public function getSubtotalAttribute(): float
    {
        return $this->so_luong * (int) round((float) $this->don_gia);
    }

    /**
     * Tien hang da dinh dang theo dinh dang tien te Viet Nam.
     *
     * @return string
     */
    public function getFormattedSubtotalAttribute(): string
    {
        return VietnameseHelper::formatCurrency($this->subtotal);
    }
}
