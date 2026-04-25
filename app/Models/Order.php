<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class Order extends Model
{
    use HasFactory, Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'total_amount',
        'status',
        'shipping_address',
        'shipping_phone',
        'delivery_time',
        'order_notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    /**
     * Get the user that owns the order.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the order items for the order.
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Calculate the total amount for the order.
     * OPTIMIZED: Use database aggregation instead of loading all items into memory
     *
     * @return float
     */
    public function calculateTotal(): float
    {
        return (float) $this->items()
            ->selectRaw('COALESCE(SUM(quantity * ROUND(unit_price, 0)), 0) as total')
            ->value('total');
    }

    /**
     * Check if the order can transition to a new status.
     *
     * @param string $newStatus
     * @return bool
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $validTransitions = [
            'Pending' => ['Delivering', 'Cancelled'],
            'Delivering' => ['Completed'],
            'Completed' => [],
            'Cancelled' => []
        ];

        return in_array($newStatus, $validTransitions[$this->status] ?? []);
    }

    /**
     * Get formatted total amount in Vietnamese currency format.
     *
     * @return string
     */
    public function getFormattedTotalAttribute(): string
    {
        return number_format((int) round((float) $this->total_amount), 0, ',', '.') . ' ₫';
    }

    /**
     * Get formatted created date in Vietnamese format (dd/MM/yyyy).
     *
     * @return string
     */
    public function getFormattedCreatedAtAttribute(): string
    {
        return $this->created_at->format('d/m/Y H:i');
    }
}
