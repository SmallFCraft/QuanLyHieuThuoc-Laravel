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
     * The attributes that are mass assignable.
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
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'ngay_het_han' => 'date',
        'so_luong_ton' => 'integer',
        'muc_canh_bao' => 'integer',
    ];

    /**
     * Get the product that owns the batch.
     */
    public function sanPham()
    {
        return $this->belongsTo(SanPham::class, 'ma_sp', 'ma_sp');
    }

    /**
     * Get the order items for this batch.
     */
    public function chiTietDonHangs()
    {
        return $this->hasMany(ChiTietDonHang::class, 'ma_lo', 'ma_lo');
    }

    /**
     * Check if the batch is expired.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->ngay_het_han->isPast();
    }

    /**
     * Check if the batch is expiring soon.
     *
     * @param int $days
     * @return bool
     */
    public function isExpiringSoon(int $days = 180): bool
    {
        if ($this->isExpired()) {
            return false;
        }

        // Carbon 3 returns a signed difference, so calling diffInDays() on the later
        // date yields a negative number and `<= $days` matched every future date.
        return Carbon::now()->diffInDays($this->ngay_het_han, true) <= $days;
    }

    /**
     * Check if the batch has low stock.
     *
     * @return bool
     */
    public function isLowStock(): bool
    {
        return $this->so_luong_ton <= $this->muc_canh_bao;
    }

    /**
     * Scope a query to only include non-expired batches.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNotExpired($query)
    {
        return $query->where('ngay_het_han', '>', Carbon::now());
    }

    /**
     * Scope a query to only include batches with stock.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInStock($query)
    {
        return $query->where('so_luong_ton', '>', 0);
    }

    /**
     * Scope a query to order by FEFO (First Expired, First Out).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFEFO($query)
    {
        return $query->orderBy('ngay_het_han', 'asc');
    }
}
