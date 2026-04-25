<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'product_id',
        'batch_id',
        'quantity',
        'unit_price',
        'batch_number',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
    ];

    /**
     * Get the order that owns the order item.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the product that owns the order item.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the batch that owns the order item.
     */
    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    /**
     * Get the subtotal for this order item.
     *
     * @return float
     */
    public function getSubtotalAttribute(): float
    {
        return $this->quantity * (int) round((float) $this->unit_price);
    }

    /**
     * Get formatted subtotal in Vietnamese currency format.
     *
     * @return string
     */
    public function getFormattedSubtotalAttribute(): string
    {
        return number_format((int) $this->subtotal, 0, ',', '.') . ' ₫';
    }
}
