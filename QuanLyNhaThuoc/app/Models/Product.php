<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;
use Carbon\Carbon;

class Product extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'ingredients',
        'usage',
        'price',
        'expiration_date',
        'stock_quantity',
        'reorder_point',
        'batch_number',
        'image',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
        'expiration_date' => 'date',
        'stock_quantity' => 'integer',
        'reorder_point' => 'integer',
    ];

    /**
     * Get the category that owns the product.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the order items for the product.
     */
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the batches for the product.
     */
    public function batches()
    {
        return $this->hasMany(ProductBatch::class);
    }

    /**
     * Get total stock across all batches.
     * Override stock_quantity attribute to return total from batches
     *
     * @return int
     */
    public function getStockQuantityAttribute(): int
    {
        // If accessing from database attribute directly
        if (array_key_exists('stock_quantity', $this->attributes)) {
            // Check if we have batches
            $batchStock = $this->batches()->sum('stock_quantity');
            if ($batchStock > 0) {
                return $batchStock;
            }
            // Fallback to product stock for legacy data
            return $this->attributes['stock_quantity'] ?? 0;
        }
        
        return $this->batches()->sum('stock_quantity');
    }

    /**
     * Check if the product is expired.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->expiration_date->isPast();
    }

    /**
     * Check if the product is expiring soon (within specified days).
     *
     * @param int $days Number of days threshold (default: 180 days / 6 months)
     * @return bool
     */
    public function isExpiringSoon(int $days = 180): bool
    {
        if ($this->isExpired()) {
            return false;
        }
        
        return $this->expiration_date->diffInDays(Carbon::now()) <= $days;
    }

    /**
     * Check if the product has low stock.
     *
     * @return bool
     */
    public function isLowStock(): bool
    {
        return $this->stock_quantity <= $this->reorder_point;
    }

    /**
     * Get formatted price in Vietnamese currency format.
     *
     * @return string
     */
    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 0, ',', '.') . ' ₫';
    }

    /**
     * Get formatted expiration date in Vietnamese format (dd/MM/yyyy).
     *
     * @return string
     */
    public function getFormattedExpirationDateAttribute(): string
    {
        return $this->expiration_date->format('d/m/Y');
    }

    /**
     * Get the product image URL.
     *
     * @return string|null
     */
    public function getImageUrlAttribute(): ?string
    {
        if ($this->image) {
            // Check if image is already a full URL (starts with http:// or https://)
            if (preg_match('/^https?:\/\//', $this->image)) {
                return $this->image;
            }
            
            // Otherwise, treat as local storage file
            return asset('storage/products/' . $this->image);
        }
        
        return asset('images/placeholder-product.png');
    }

    /**
     * Scope a query to only include non-expired products.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNotExpired($query)
    {
        return $query->where('expiration_date', '>', Carbon::now());
    }

    /**
     * Scope a query to only include products expiring soon.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeExpiringSoon($query, int $days = 180)
    {
        return $query->whereBetween('expiration_date', [
            Carbon::now(),
            Carbon::now()->addDays($days)
        ]);
    }

    /**
     * Scope a query to only include low stock products.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLowStock($query)
    {
        return $query->whereColumn('stock_quantity', '<=', 'reorder_point');
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
