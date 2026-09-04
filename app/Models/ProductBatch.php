<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use Carbon\Carbon;

class ProductBatch extends Model
{
    use HasFactory, Auditable;

    protected $primaryKey = 'MaLo';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'MaSP',
        'batch_number',
        'expiration_date',
        'stock_quantity',
        'reorder_point',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expiration_date' => 'date',
        'stock_quantity' => 'integer',
        'reorder_point' => 'integer',
    ];

    /**
     * Get the product that owns the batch.
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'MaSP', 'MaSP');
    }

    /**
     * Get the order items for this batch.
     */
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'MaLo', 'MaLo');
    }

    /**
     * Check if the batch is expired.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->expiration_date->isPast();
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
        return Carbon::now()->diffInDays($this->expiration_date, true) <= $days;
    }

    /**
     * Check if the batch has low stock.
     *
     * @return bool
     */
    public function isLowStock(): bool
    {
        return $this->stock_quantity <= $this->reorder_point;
    }

    /**
     * Scope a query to only include non-expired batches.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNotExpired($query)
    {
        return $query->where('expiration_date', '>', Carbon::now());
    }

    /**
     * Scope a query to only include batches with stock.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInStock($query)
    {
        return $query->where('stock_quantity', '>', 0);
    }

    /**
     * Scope a query to order by FEFO (First Expired, First Out).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFEFO($query)
    {
        return $query->orderBy('expiration_date', 'asc');
    }
}
