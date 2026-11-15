<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use Carbon\Carbon;

class LoSanPham extends Model
{
    use HasFactory, Auditable;

    protected $table = 'lo_san_phams';

    protected $primaryKey = 'ma_lo';

    /**
     * Các thuộc tính cho phép gán hàng loạt.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ma_sp',
        'so_lo',
        'ngay_het_han',
        'so_luong_ton',
        'muc_canh_bao',
    ];

    /**
     * Các thuộc tính nên ép kiểu.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'ngay_het_han' => 'date',
        'so_luong_ton' => 'integer',
        'muc_canh_bao' => 'integer',
    ];

    /**
     * Sản phẩm mà lô này thuộc về.
     */
    public function sanPham()
    {
        return $this->belongsTo(SanPham::class, 'ma_sp', 'ma_sp');
    }

    /**
     * Các chi tiết đơn hàng dùng lô sản phẩm này.
     */
    public function chiTietDonHangs()
    {
        return $this->hasMany(ChiTietDonHang::class, 'ma_lo', 'ma_lo');
    }

    /**
     * Kiểm tra lô sản phẩm đã hết hạn.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->ngay_het_han->isPast();
    }

    /**
     * Kiểm tra lô sản phẩm sắp hết hạn.
     *
     * @param int $days
     * @return bool
     */
    public function isExpiringSoon(int $days = 180): bool
    {
        if ($this->isExpired()) {
            return false;
        }

        // Carbon 3 trả về hiệu có dấu, nên gọi diffInDays() trên ngày muộn hơn sẽ cho
        // kết quả âm và `<= $days` khớp với mọi ngày tương lai.
        return Carbon::now()->diffInDays($this->ngay_het_han, true) <= $days;
    }

    /**
     * Kiểm tra lô sản phẩm có tồn kho thấp.
     *
     * @return bool
     */
    public function isLowStock(): bool
    {
        return $this->so_luong_ton <= $this->muc_canh_bao;
    }

    /**
     * Giới hạn truy vấn chỉ lấy các lô sản phẩm chưa hết hạn.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNotExpired($query)
    {
        return $query->where('ngay_het_han', '>', Carbon::now());
    }

    /**
     * Giới hạn truy vấn chỉ lấy các lô sản phẩm còn tồn kho.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInStock($query)
    {
        return $query->where('so_luong_ton', '>', 0);
    }

    /**
     * Sắp xếp truy vấn theo FEFO (First Expired, First Out — hết hạn trước xuất trước).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFEFO($query)
    {
        return $query->orderBy('ngay_het_han', 'asc');
    }
}
