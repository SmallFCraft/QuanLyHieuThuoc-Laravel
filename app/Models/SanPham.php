<?php

namespace App\Models;

use App\Helpers\VietnameseHelper;
use App\Support\TaoMaSanPham;
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
     * Các thuộc tính cho phép gán hàng loạt.
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
     * Các thuộc tính nên ép kiểu.
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
                $sanPham->ma_san_pham = TaoMaSanPham::maSanPhamTheoDanhMuc($danhMuc);
            }
        });

        // Giữ đồng bộ ở mỗi lần ghi thay vì từng nơi gọi, để sản phẩm tạo bởi crawler,
        // form quản trị hay factory đều được tìm kiếm cùng một cách.
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
     * Danh mục mà sản phẩm thuộc về.
     */
    public function danhMuc()
    {
        return $this->belongsTo(DanhMuc::class, 'ma_dm', 'ma_dm');
    }

    /**
     * Các chi tiết đơn hàng chứa sản phẩm này.
     */
    public function chiTietDonHangs()
    {
        return $this->hasMany(ChiTietDonHang::class, 'ma_sp', 'ma_sp');
    }

    /**
     * Các lô sản phẩm của sản phẩm này.
     */
    public function cacLo()
    {
        return $this->hasMany(LoSanPham::class, 'ma_sp', 'ma_sp');
    }

    /**
     * Trả về tồn kho từ cột đã đồng bộ theo mặc định.
     * Nếu các lô đã được eager-loaded, ưu tiên tổng hợp trong bộ nhớ.
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
     * Kiểm tra sản phẩm đã hết hạn.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->ngay_het_han->isPast();
    }

    /**
     * Kiểm tra sản phẩm sắp hết hạn (trong số ngày chỉ định).
     *
     * @param int $days Ngưỡng số ngày (mặc định: 180 ngày / 6 tháng)
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
     * Kiểm tra sản phẩm có tồn kho thấp.
     *
     * @return bool
     */
    public function isLowStock(): bool
    {
        return $this->so_luong_ton <= $this->muc_canh_bao;
    }

    /**
     * Giá sản phẩm theo số đồng Việt Nam nguyên.
     */
    public function getPriceVndAttribute(): int
    {
        return (int) round((float) ($this->gia ?? 0));
    }

    /**
     * Lưu giá theo số đồng Việt Nam nguyên để tránh tổng VND có phần lẻ.
     */
    public function setPriceAttribute($value): void
    {
        $this->attributes['gia'] = (int) round((float) ($value ?? 0));
    }

    /**
     * Giá đã định dạng theo định dạng tiền tệ Việt Nam.
     *
     * @return string
     */
    public function getFormattedPriceAttribute(): string
    {
        return VietnameseHelper::formatCurrency($this->price_vnd);
    }

    /**
     * Ngày hết hạn đã định dạng theo định dạng Việt Nam (dd/MM/yyyy).
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
     * Hạn dùng hiện trên giao diện bán lẻ, theo cách các chuỗi nhà thuốc thường ghi:
     * "36 tháng" kể từ ngày sản xuất. Giữ nguyên đơn vị nhà sản xuất công bố — viết
     * lại 36 tháng thành "3 năm" sẽ không còn khớp với nhãn gốc. Null khi không công
     * bố, nên nơi gọi ẩn dòng này thay vì đoán.
     */
    public function getShelfLifeTextAttribute(): ?string
    {
        $months = (int) ($this->han_dung_thang ?? 0);

        return $months > 0 ? $months . ' tháng' : null;
    }

    /**
     * URL hình ảnh sản phẩm.
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
     * Giới hạn truy vấn chỉ lấy các sản phẩm chưa hết hạn.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNotExpired($query)
    {
        return $query->where('ngay_het_han', '>', Carbon::now());
    }

    /**
     * Giới hạn truy vấn chỉ lấy các sản phẩm sắp hết hạn.
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
     * Giới hạn truy vấn chỉ lấy các sản phẩm tồn kho thấp.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLowStock($query)
    {
        return $query->whereColumn('so_luong_ton', '<=', 'muc_canh_bao');
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

    /**
     * Tìm kiếm sản phẩm phía khách hàng. Chung bởi trang catalog, trang kết quả tìm
     * kiếm và endpoint autocomplete dùng, để cả ba trả lời một truy vấn cùng một cách.
     *
     * Mỗi từ phải khớp (AND, không phải OR). Với catalog crawl nghìn dòng, OR các từ
     * của "thuốc ho cho trẻ" khớp 2.875 trong 3.132 sản phẩm — bộ kết quả đông đến
     * vậy không khác gì không có bộ lọc.
     *
     * So khớp chạy trên các cột đã fold để truy vấn không dấu vẫn hoạt động: collation
     * của MySQL fold dấu thanh nhưng không fold "đ", nên "dau dau" không ra gì trong
     * khi "đau đầu" ra 30 kết quả.
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

        // Lặp lại LIKE cho một từ bị lặp lại không thêm gì, nên các điều kiện được
        // loại trùng tại đây thay vì trong searchWords() — bộ xếp hạng bên dưới cần
        // nguyên câu trọn vẹn.
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
     * Xếp hạng kết quả tìm kiếm bán lẻ: cả cụm từ trong tên đầu tiên, rồi mỗi từ
     * trong tên, rồi mọi thứ còn lại (một thành phần khớp).
     *
     * Không có điều này, "paracetamol" dẫn đầu bởi sáu sản phẩm không hề ghi tên nó —
     * chỉ nhắc nó sâu trong phần hướng dẫn sử dụng — trong khi đúng các sản phẩm
     * paracetamol nằm sát xuống dưới trang.
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

        // Mở đầu bằng cụm từ thắng việc chỉ chứa trong đó: tìm "panadol" phải mở đầu
        // bằng "Panadol Extra", không phải một sản phẩm kết hợp chỉ nhắc tên nó ở giữa.
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
     * Các từ truy vấn đã fold, theo thứ tự người gõ. Ký tự đơn bị loại: một chữ cái
     * khớp gần cả catalog và không nói gì về ý định.
     *
     * Từ lặp lại được giữ nguyên — "đau đầu" fold ra vẫn là "dau dau", và gộp nó về
     * một từ duy nhất sẽ mất câu mà bộ xếp hạng dùng để tính điểm.
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
     * Vô hiệu hoá các ký tự đại diện LIKE mà khách gõ vào. Một dấu "%" trần chỉ
     * khớp cả catalog và đọc như một tìm kiếm bị lỗi.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
     * Tìm kiếm sản phẩm phía quản trị. Chung bởi endpoint DataTables, xuất sản phẩm
     * và màn hình tồn kho cùng xuất của nó, để một cột tìm kiếm được thêm vào đây
     * không thể mất mặt ở một trong những chỗ đó.
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
                ->orWhereHas('danhMuc', fn ($danhMucQuery) => $danhMucQuery->where('ten', 'like', $like));
        });
    }

    /**
     * Bộ lọc trạng thái tồn kho, chung bởi màn hình tồn kho và xuất của nó.
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
