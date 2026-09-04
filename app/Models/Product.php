<?php

namespace App\Models;

use App\Helpers\VietnameseHelper;
use App\Support\ProductCodeGenerator;
use App\Support\VietnameseText;
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
        'name_folded',
        'ingredients',
        'ingredients_folded',
        'usage',
        'shelf_life_months',
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
        'shelf_life_months' => 'integer',
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

        // Kept in sync on every write rather than at the call sites, so a product
        // created by the crawler, the admin form or a factory is searchable the
        // same way.
        static::saving(function (Product $product): void {
            if ($product->isDirty('name')) {
                $product->name_folded = VietnameseText::fold((string) $product->name);
            }

            if ($product->isDirty('ingredients')) {
                $product->ingredients_folded = VietnameseText::fold((string) $product->ingredients);
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

        // Carbon 3 returns a signed difference, so calling diffInDays() on the later
        // date yields a negative number and `<= $days` matched every future date.
        return Carbon::now()->diffInDays($this->expiration_date, true) <= $days;
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
     * Shelf life for the storefront, the way pharmacy chains state it: "36 tháng"
     * from the date of manufacture. Kept in the unit the manufacturer published —
     * rewriting 36 months as "3 năm" would no longer match the source label.
     * Null when none was published, so callers hide the row instead of guessing.
     */
    public function getShelfLifeTextAttribute(): ?string
    {
        $months = (int) ($this->shelf_life_months ?? 0);

        return $months > 0 ? $months . ' tháng' : null;
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
     * The customer-facing product search. Shared by the catalog screen, the search
     * results page and the autocomplete endpoint so all three answer a query the
     * same way.
     *
     * Every word must match (AND, not OR). With a crawled catalog of thousands of
     * rows, ORing the words of "thuốc ho cho trẻ" matched 2,875 of 3,132 products —
     * a result set that size is indistinguishable from no filter at all.
     *
     * Matching runs on the folded columns so an accent-free query works: the MySQL
     * collation folds tone marks but not "đ", so "dau dau" found nothing while
     * "đau đầu" found 30.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeStorefrontSearch($query, ?string $term)
    {
        $words = self::searchWords($term);

        if ($words === []) {
            return $query;
        }

        // Repeating a LIKE for a repeated word adds nothing, so the conditions are
        // de-duplicated here rather than in searchWords() — the ranking below needs
        // the phrase intact.
        foreach (array_unique($words) as $word) {
            $like = '%' . self::escapeLike($word) . '%';

            $query->where(function ($inner) use ($like) {
                $inner->where('name_folded', 'like', $like)
                    ->orWhere('ingredients_folded', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * Rank a storefront search: whole phrase in the name first, then all words in
     * the name, then anything left (an ingredient match).
     *
     * Without this, "paracetamol" led with six products that never name it —
     * they merely list it deep in their usage text — while the actual paracetamol
     * products sat further down the page.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrderBySearchRelevance($query, ?string $term)
    {
        $words = self::searchWords($term);

        if ($words === []) {
            return $query;
        }

        $phrase = self::escapeLike(implode(' ', $words));

        // Starting with the phrase beats merely containing it: a search for
        // "panadol" should open with "Panadol Extra", not a combination product
        // that mentions it mid-name.
        $sql = 'CASE WHEN name_folded LIKE ? THEN 3 WHEN name_folded LIKE ? THEN 2 ';
        $bindings = [$phrase . '%', '%' . $phrase . '%'];

        $nameHits = [];
        foreach ($words as $word) {
            $nameHits[] = 'name_folded LIKE ?';
            $bindings[] = '%' . self::escapeLike($word) . '%';
        }

        $sql .= 'WHEN ' . implode(' AND ', $nameHits) . ' THEN 1 ELSE 0 END DESC';

        return $query
            ->orderByRaw($sql, $bindings)
            ->orderByDesc('views_count')
            ->orderBy('MaSP');
    }

    /**
     * Folded query words, in the order typed. Single characters are dropped: one
     * letter matches most of the catalog and says nothing about intent.
     *
     * Repeats are kept — "đau đầu" folds to "dau dau", and collapsing that to a
     * single word would lose the phrase the ranking scores against.
     *
     * @return array<string>
     */
    public static function searchWords(?string $term): array
    {
        $folded = VietnameseText::fold((string) $term);

        if ($folded === '') {
            return [];
        }

        return array_values(array_filter(
            explode(' ', $folded),
            fn (string $word) => mb_strlen($word) >= 2
        ));
    }

    /**
     * Neutralise LIKE wildcards typed by the customer. A bare "%" was matching the
     * entire catalog and reading as a broken search.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
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

        $like = '%' . self::escapeLike($term) . '%';

        return $query->where(function ($inner) use ($like) {
            $inner->where('name', 'like', $like)
                ->orWhere('batch_number', 'like', $like)
                ->orWhere('product_code', 'like', $like)
                ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', 'like', $like));
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
