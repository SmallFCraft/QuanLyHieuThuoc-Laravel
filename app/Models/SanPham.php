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
     * Cac thuoc tinh cho phep gan hang loat.
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
     * Cac thuoc tinh nen ep kieu.
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

        // Giu dong bo o moi lan ghi thay vi tung noi goi, de san pham tao boi crawler,
        // form quan tri hay factory deu duoc tim kiem cung mot cach.
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
     * Danh muc ma san pham thuoc ve.
     */
    public function danhMuc()
    {
        return $this->belongsTo(DanhMuc::class, 'ma_dm', 'ma_dm');
    }

    /**
     * Cac chi tiet don hang chua san pham nay.
     */
    public function chiTietDonHangs()
    {
        return $this->hasMany(ChiTietDonHang::class, 'ma_sp', 'ma_sp');
    }

    /**
     * Cac lo san pham cua san pham nay.
     */
    public function cacLo()
    {
        return $this->hasMany(LoSanPham::class, 'ma_sp', 'ma_sp');
    }

    /**
     * Tra ve ton kho tu cot da dong bo theo mac dinh.
     * Neu cac lo da duoc eager-loaded, uu tong hop trong bo nho.
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
     * Kiem tra san pham da het han.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->ngay_het_han->isPast();
    }

    /**
     * Kiem tra san pham sap het han (trong so ngay chi dinh).
     *
     * @param int $days Nguong so ngay (mac dinh: 180 ngay / 6 thang)
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
     * Kiem tra san pham co ton kho thap.
     *
     * @return bool
     */
    public function isLowStock(): bool
    {
        return $this->so_luong_ton <= $this->muc_canh_bao;
    }

    /**
     * Gia san pham theo so dong Viet Nam nguyen.
     */
    public function getPriceVndAttribute(): int
    {
        return (int) round((float) ($this->gia ?? 0));
    }

    /**
     * Luu gia theo so dong Viet Nam nguyen de tranh tong VND co phan le.
     */
    public function setPriceAttribute($value): void
    {
        $this->attributes['gia'] = (int) round((float) ($value ?? 0));
    }

    /**
     * Gia da dinh dang theo dinh dang tien te Viet Nam.
     *
     * @return string
     */
    public function getFormattedPriceAttribute(): string
    {
        return VietnameseHelper::formatCurrency($this->price_vnd);
    }

    /**
     * Ngay het han da dinh dang theo dinh dang Viet Nam (dd/MM/yyyy).
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
     * Han dung hien tren giao dien ban le, theo cach cac chui nha thuoc thuong ghi:
     * "36 thang" ke tu ngay san xuat. Giu nguyen don vi nha san xuat cong bo — viet
     * lai 36 thang thanh "3 nam" se khong con khop voi nhan goc. Null khi khong cong
     * bo, nen noi goi an dong nay thay vi doan.
     */
    public function getShelfLifeTextAttribute(): ?string
    {
        $months = (int) ($this->han_dung_thang ?? 0);

        return $months > 0 ? $months . ' tháng' : null;
    }

    /**
     * URL hinh anh san pham.
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
     * Gioi han truy van chi lay cac san pham chua het han.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNotExpired($query)
    {
        return $query->where('ngay_het_han', '>', Carbon::now());
    }

    /**
     * Gioi han truy van chi lay cac san pham sap het han.
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
     * Gioi han truy van chi lay cac san pham ton kho thap.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLowStock($query)
    {
        return $query->whereColumn('so_luong_ton', '<=', 'muc_canh_bao');
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

    /**
     * Tim kiem san pham phia khach hang. Chung bo trang catalog, trang ket qua tim
     * kiem va endpoint autocomplete dung, de ca ba tra loi mot truy van cung mot cach.
     *
     * Moi tu phai khop (AND, khong phai OR). Voi catalog crawl nghin dong, OR cac tu
     * cua "thuoc ho cho tre" khop 2.875 trong 3.132 san pham — bo ket qua dong den
     * vay khong khac gi khong co bo loc.
     *
     * So khop chay tren cac cot da fold de truy van khong dau van hoat dong: collation
     * cua MySQL fold dau thanh nhung khong fold "d", nen "dau dau" khong ra gi trong
     * khi "dau dau" ra 30 ket qua.
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

        // Lap lai LIKE cho mot tu bi lap lai khong them gi, nen cac dieu kien duoc
        // loai trung tai day thay vi trong searchWords() — bo xep hang ben duoi can
        // nguyen cau tron ven.
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
     * Xep hang ket qua tim kiem ban le: cu ca cum tu trong ten dau tien, roi moi tu
     * trong ten, roi moi thu con lai (mot thanh phan khop).
     *
     * Khong co dieu nay, "paracetamol" dan dau boi sau san pham khong he ghi ten no —
     * chi neu no sau trong phan huong dan su dung — trong khi dung cac san pham
     * paracetamol nam sat xuong duoi trang.
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

        // Mo dau bang cum tu thang viec chi chua trong do: tim "panadol" phai mo dau
        // bang "Panadol Extra", khong phai mot san pham ket hop chi nhac ten no o giua.
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
     * Cac tu truy van da fold, theo thu tu nguoi go. Ky tu don bi loai: mot chu cai
     * khop gan ca catalog va khong noi gi ve y dinh.
     *
     * Tu lap lai duoc giu nguyen — "dau dau" fold ra van la "dau dau", va gop no ve
     * mot tu duy nhat se mat cau ma bo xep hang dung de tinh diem.
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
     * Vo hieu hoa cac ky tu dai dien LIKE ma khach go vao. Mot dau "%" tran chi
     * khop ca catalog va doc nhu mot tim kiem bi loi.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
     * Tim kiem san pham phia quan tri. Chung bo endpoint DataTables, xuat san pham
     * va man hinh ton kho cung xuat cua no, de mot cot tim kiem duoc them vao day
     * khong the mat mat o mot trong nhung cho do.
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
     * Bo loc trang thai ton kho, chung bo man hinh ton kho va xuat cua no.
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
