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
     * Cac thuoc tinh cho phep gan hang loat.
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
     * Cac thuoc tinh nen ep kieu.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'ngay_het_han' => 'date',
        'so_luong_ton' => 'integer',
        'muc_canh_bao' => 'integer',
    ];

    /**
     * San pham ma lo nay thuoc ve.
     */
    public function sanPham()
    {
        return $this->belongsTo(SanPham::class, 'ma_sp', 'ma_sp');
    }

    /**
     * Cac chi tiet don hang dung lo san pham nay.
     */
    public function chiTietDonHangs()
    {
        return $this->hasMany(ChiTietDonHang::class, 'ma_lo', 'ma_lo');
    }

    /**
     * Kiem tra lo san pham da het han.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->ngay_het_han->isPast();
    }

    /**
     * Kiem tra lo san pham sap het han.
     *
     * @param int $days
     * @return bool
     */
    public function isExpiringSoon(int $days = 180): bool
    {
        if ($this->isExpired()) {
            return false;
        }

        // Carbon 3 tra ve hieu co dau, nen goi diffInDays() tren ngay muon hon se cho
        // ket qua am va `<= $days` khop voi moi ngay tuong lai.
        return Carbon::now()->diffInDays($this->ngay_het_han, true) <= $days;
    }

    /**
     * Kiem tra lo san pham co ton kho thap.
     *
     * @return bool
     */
    public function isLowStock(): bool
    {
        return $this->so_luong_ton <= $this->muc_canh_bao;
    }

    /**
     * Gioi han truy van chi lay cac lo san pham chua het han.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNotExpired($query)
    {
        return $query->where('ngay_het_han', '>', Carbon::now());
    }

    /**
     * Gioi han truy van chi lay cac lo san pham con ton kho.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInStock($query)
    {
        return $query->where('so_luong_ton', '>', 0);
    }

    /**
     * Sap xep truy van theo FEFO (First Expired, First Out — het han truoc xuat truoc).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFEFO($query)
    {
        return $query->orderBy('ngay_het_han', 'asc');
    }
}
