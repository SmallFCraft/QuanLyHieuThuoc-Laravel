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

class SanPham extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected $table = 'san_phams';

    protected $primaryKey = 'ma_sp';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ma_dm',
        'ma_san_pham',
        'ten',
        'ten_khong_dau',
        'thanh_phan',
        'thanh_phan_khong_dau',
        'cach_dung',
        'han_dung_thang',
        'gia',
        'ngay_het_han',
        'so_luong_ton',
        'muc_canh_bao',
        'so_lo',
        'hinh_anh',
        'luot_xem',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'gia' => 'decimal:2',
        'ngay_het_han' => 'date',
        'so_luong_ton' => 'integer',
        'muc_canh_bao' => 'integer',
        'han_dung_thang' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (SanPham $sanPham): void {
            if (filled($sanPham->ma_san_pham) || blank($sanPham->ma_dm)) {
                return;
            }

            $danhMuc = DanhMuc::find($sanPham->ma_dm);

            if ($danhMuc instanceof DanhMuc) {
                $sanPham->ma_san_pham = ProductCodeGenerator::productCodeForCategory($danhMuc);
            }
        });

        // Kept in sync on every write rather than at the call sites, so a product
        // created by the crawler, the admin form or a factory is searchable the
        // same way.
        static::saving(function (SanPham $sanPham): void {
            if ($sanPham->isDirty('ten')) {
                $sanPham->ten_khong_dau = VietnameseText::fold((string) $sanPham->ten);
            }

            if ($sanPham->isDirty('thanh_phan')) {
                $sanPham->thanh_phan_khong_dau = VietnameseText::fold((string) $sanPham->thanh_phan);
            }
        });
    }

    /**
     * Get the category that owns the product.
     */
    public function danhMuc()
    {
        return $this->belongsTo(DanhMuc::class, 'ma_dm', 'ma_dm');
    }

    /**
     * Get the order items for the product.
     */
    public function chiTietDonHangs()
    {
        return $this->hasMany(ChiTietDonHang::class, 'ma_sp', 'ma_sp');
    }

    /**
     * Get the batches for the product.
     */
    public function cacLo()
    {
        return $this->hasMany(LoSanPham::class, 'ma_sp', 'ma_sp');
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
        if ($this->relationLoaded('cacLo') && $this->cacLo->isNotEmpty()) {
            return (int) $this->cacLo->sum('stock_quantity');
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
        return $this->ngay_het_han->isPast();
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
        return Carbon::now()->diffInDays($this->ngay_het_han, true) <= $days;
    }

    /**
     * Check if the product has low stock.
     *
     * @return bool
     */
    public function isLowStock(): bool
    {
        return $this->so_luong_ton <= $this->muc_canh_bao;
    }

    /**
     * Get the product price as whole Vietnamese dong.
     */
    public function getPriceVndAttribute(): int
    {
        return (int) round((float) ($this->gia ?? 0));
    }

    /**
     * Store prices as whole Vietnamese dong to avoid fractional VND totals.
     */
    public function setPriceAttribute($value): void
    {
        $this->attributes['gia'] = (int) round((float) ($value ?? 0));
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
        return $this->ngay_het_han->format('d/m/Y');
    }

    public function getDisplayCodeAttribute(): string
    {
        return $this->ma_san_pham ?: '#' . $this->ma_sp;
    }

    /**
     * Shelf life for the storefront, the way pharmacy chains state it: "36 tháng"
     * from the date of manufacture. Kept in the unit the manufacturer published —
     * rewriting 36 months as "3 năm" would no longer match the source label.
     * Null when none was published, so callers hide the row instead of guessing.
     */
    public function getShelfLifeTextAttribute(): ?string
    {
        $months = (int) ($this->han_dung_thang ?? 0);

        return $months > 0 ? $months . ' tháng' : null;
    }

    /**
     * Get the product image URL.
     *
     * @return string|null
     */
    public function getImageUrlAttribute(): ?string
    {
        if (blank($this->hinh_anh)) {
            return null;
        }

        if (preg_match('/^https?:\/\//', $this->hinh_anh)) {
            return $this->hinh_anh;
        }

        $path = str_contains($this->hinh_anh, '/')
            ? ltrim($this->hinh_anh, '/')
            : 'products/' . $this->hinh_anh;

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
        return $query->where('ngay_het_han', '>', Carbon::now());
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
        return $query->whereBetween('ngay_het_han', [
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
        return $query->whereColumn('so_luong_ton', '<=', 'muc_canh_bao');
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
                $inner->where('ten_khong_dau', 'like', $like)
                    ->orWhere('thanh_phan_khong_dau', 'like', $like);
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
        $sql = 'CASE WHEN ten_khong_dau LIKE ? THEN 3 WHEN ten_khong_dau LIKE ? THEN 2 ';
        $bindings = [$phrase . '%', '%' . $phrase . '%'];

        $nameHits = [];
        foreach ($words as $word) {
            $nameHits[] = 'ten_khong_dau LIKE ?';
            $bindings[] = '%' . self::escapeLike($word) . '%';
        }

        $sql .= 'WHEN ' . implode(' AND ', $nameHits) . ' THEN 1 ELSE 0 END DESC';

        return $query
            ->orderByRaw($sql, $bindings)
            ->orderByDesc('luot_xem')
            ->orderBy('ma_sp');
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
            $inner->where('ten', 'like', $like)
                ->orWhere('so_lo', 'like', $like)
                ->orWhere('ma_san_pham', 'like', $like)
                ->orWhereHas('danhMuc', fn ($danhMucQuery) => $danhMucQuery->where('name', 'like', $like));
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
            'expired' => $query->where('ngay_het_han', '<', now()),
            'out_of_stock' => $query->where('so_luong_ton', 0),
            default => $query,
        };
    }
}
