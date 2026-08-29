<?php

namespace App\Models;

use App\Helpers\VietnameseHelper;
use App\Support\ProductCodeGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use App\Traits\Auditable;
use Carbon\Carbon;

class Product extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected $primaryKey = 'MaSP';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'MaDM',
        'product_code',
        'name',
        'ingredients',
        'usage',
        'price',
        'expiration_date',
        'stock_quantity',
        'reorder_point',
        'batch_number',
        'image',
        'views_count',
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

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (filled($product->product_code) || blank($product->MaDM)) {
                return;
            }

            $category = Category::find($product->MaDM);

            if ($category instanceof Category) {
                $product->product_code = ProductCodeGenerator::productCodeForCategory($category);
            }
        });
    }

    /**
     * Get the category that owns the product.
     */
    public function category()
    {
        return $this->belongsTo(Category::class, 'MaDM', 'MaDM');
    }

    /**
     * Get the order items for the product.
     */
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'MaSP', 'MaSP');
    }

    /**
     * Get the batches for the product.
     */
    public function batches()
    {
        return $this->hasMany(ProductBatch::class, 'MaSP', 'MaSP');
    }

    /**
     * Return stock from the synced column by default.
     * If batches were already eager-loaded, prefer the in-memory aggregate.
     *
     * @param mixed $value
     * @return int
     */
    public function getStockQuantityAttribute($value): int
    {
        if ($this->relationLoaded('batches') && $this->batches->isNotEmpty()) {
            return (int) $this->batches->sum('stock_quantity');
        }

        return (int) ($value ?? 0);
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
     * Get the product price as whole Vietnamese dong.
     */
    public function getPriceVndAttribute(): int
    {
        return (int) round((float) ($this->price ?? 0));
    }

    /**
     * Store prices as whole Vietnamese dong to avoid fractional VND totals.
     */
    public function setPriceAttribute($value): void
    {
        $this->attributes['price'] = (int) round((float) ($value ?? 0));
    }

    /**
     * Get formatted price in Vietnamese currency format.
     *
     * @return string
     */
    public function getFormattedPriceAttribute(): string
    {
        return VietnameseHelper::formatCurrency($this->price_vnd);
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

    public function getDisplayCodeAttribute(): string
    {
        return $this->product_code ?: '#' . $this->MaSP;
    }

    /**
     * Get the product image URL.
     *
     * @return string|null
     */
    public function getImageUrlAttribute(): ?string
    {
        if (blank($this->image)) {
            return null;
        }

        if (preg_match('/^https?:\/\//', $this->image)) {
            return $this->image;
        }

        $path = str_contains($this->image, '/')
            ? ltrim($this->image, '/')
            : 'products/' . $this->image;

        return is_file(Storage::disk('public')->path($path))
            ? asset('storage/' . $path)
            : null;
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

    /**
     * The admin product search. Shared by the DataTables endpoint, the products
     * export and the inventory screen/export so a searchable column added here
     * cannot go missing from one of them.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function ($inner) use ($term) {
            $inner->where('name', 'like', "%{$term}%")
                ->orWhere('batch_number', 'like', "%{$term}%")
                ->orWhere('product_code', 'like', "%{$term}%")
                ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', 'like', "%{$term}%"));
        });
    }

    /**
     * The inventory status filter, shared by the inventory screen and its export.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInventoryStatus($query, ?string $status, int $days = 30)
    {
        return match ($status) {
            'low_stock' => $query->lowStock(),
            'expiring' => $query->expiringSoon($days),
            'expired' => $query->where('expiration_date', '<', now()),
            'out_of_stock' => $query->where('stock_quantity', 0),
            default => $query,
        };
    }
}
