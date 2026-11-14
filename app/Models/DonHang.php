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
     * Cac thuoc tinh cho phep gan hang loat.
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
     * Cac thuoc tinh nen ep kieu.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tong_tien' => 'decimal:2',
    ];

    /**
     * Nguoi dung so huu don hang.
     */
    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'ma_nd', 'ma_nd');
    }

    /**
     * Cac chi tiet don hang cua don hang nay.
     */
    public function chiTietDonHangs()
    {
        return $this->hasMany(ChiTietDonHang::class, 'ma_dh', 'ma_dh');
    }

    /**
     * Tong tien da dinh dang theo dinh dang tien te Viet Nam.
     *
     * @return string
     */
    public function getFormattedTotalAttribute(): string
    {
        return VietnameseHelper::formatCurrency($this->tong_tien);
    }

    /**
     * Ngay tao da dinh dang theo dinh dang Viet Nam (dd/MM/yyyy).
     *
     * @return string
     */
    public function getFormattedCreatedAtAttribute(): string
    {
        return $this->created_at->format('d/m/Y H:i');
    }
}
