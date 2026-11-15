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
     * Các thuộc tính cho phép gán hàng loạt.
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
     * Các thuộc tính nên ép kiểu.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tong_tien' => 'decimal:2',
    ];

    /**
     * Người dùng sở hữu đơn hàng.
     */
    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'ma_nd', 'ma_nd');
    }

    /**
     * Các chi tiết đơn hàng của đơn hàng này.
     */
    public function chiTietDonHangs()
    {
        return $this->hasMany(ChiTietDonHang::class, 'ma_dh', 'ma_dh');
    }

    /**
     * Tổng tiền đã định dạng theo định dạng tiền tệ Việt Nam.
     *
     * @return string
     */
    public function getFormattedTotalAttribute(): string
    {
        return VietnameseHelper::formatCurrency($this->tong_tien);
    }

    /**
     * Ngày tạo đã định dạng theo định dạng Việt Nam (dd/MM/yyyy).
     *
     * @return string
     */
    public function getFormattedCreatedAtAttribute(): string
    {
        return $this->created_at->format('d/m/Y H:i');
    }
}
