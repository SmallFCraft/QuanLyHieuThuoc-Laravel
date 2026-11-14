<?php

namespace App\Models;

use App\Support\VietnameseText;
use Illuminate\Database\Eloquent\Model;

class HoSoSucKhoe extends Model
{
    protected $table = 'ho_so_suc_khoes';

    protected $primaryKey = 'ma_hs';

    protected $fillable = [
        'ma_nd',
        'allergies',
        'chronic_conditions',
        'current_medications',
        'is_pregnant',
        'is_breastfeeding',
        'weight_kg',
        'notes',
        'consented_at',
        'ai_consent_at',
    ];

    protected $casts = [
        // Du lieu suc khoe la nhay cam: ma hoa khi luu, giai ma chi khi doc.
        'allergies' => 'encrypted:array',
        'chronic_conditions' => 'encrypted:array',
        'current_medications' => 'encrypted:array',
        'notes' => 'encrypted',
        'is_pregnant' => 'boolean',
        'is_breastfeeding' => 'boolean',
        'weight_kg' => 'float',
        'consented_at' => 'datetime',
        'ai_consent_at' => 'datetime',
    ];

    protected $hidden = [
        'allergies',
        'chronic_conditions',
        'current_medications',
        'notes',
    ];

    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'ma_nd', 'ma_nd');
    }

    /**
     * Khong thu gi trong ho so nay duoc phep anh huong den sang loc hoac kiem tra
     * an toan truoc khi nguoi da dong y ro rang.
     */
    public function hasConsented(): bool
    {
        return $this->consented_at !== null;
    }

    /**
     * Rieng biet voi hasConsented(): dong y cho duoc si doc ho so khong phai la dong y
     * gui du lieu do den mot nha cung cap AI.
     */
    public function hasAiConsent(): bool
    {
        return $this->ai_consent_at !== null;
    }

    /**
     * Nhung gi nguoi go vao truong tag de co nghia "khong co gi khai bao".
     *
     * Khong phai tien su y khoa: mot chu "Khong" thu van trong danh sach di ung lam
     * knownTerms() khong con rong, va dieu do lam SangLocYTeService::isStepCoveredByProfile()
     * bo qua buoc 4 cua tro ly — nen sang loc khong bao gio hoi ve benh man tinh, di ung,
     * co thai hay cho con bu, de roi mot vong hoi lam ro hoi thay.
     *
     * Dong thoi gi cot da ma hoa sach se, vi AnToanThuocService doc `allergies` truc tiep
     * va quay ve so khop chuoi con voi ten san pham cho tu khoa tu 4 ky tu tro len.
     * "Khong" fold ra chuoi 5 ky tu, nen chi cach mot ten san pham xui la chan gio hang
     * voi mot canh bao di ung vo co.
     */
    public const NEGATION_TERMS = [
        'khong', 'khong co', 'khong co gi', 'khong bi gi', 'ko', 'ko co', 'ko co gi',
        'k', 'kg', 'chua', 'chua co', 'chang co', 'no', 'none', 'nothing', 'nil',
        'n a', 'na', 'trong', 'khong biet', '0', 'x',
    ];

    /**
     * fold() loai dau cau, nen "N/A", "N.A." va "-" deu hoac roi vao danh sach tren
     * hoac fold ra chuoi rong.
     */
    public static function isNegationTerm(string $term): bool
    {
        $folded = VietnameseText::fold($term);

        return $folded === '' || in_array($folded, self::NEGATION_TERMS, true);
    }

    /**
     * Danh sach da lam phang, viet thuong, cua moi tu khoa ma cac kiem tra an toan
     * dung de so khop.
     */
    public function knownTerms(): array
    {
        return collect([
            ...($this->allergies ?? []),
            ...($this->chronic_conditions ?? []),
            ...($this->current_medications ?? []),
        ])
            ->map(fn ($term) => mb_strtolower(trim((string) $term), 'UTF-8'))
            ->filter()
            ->unique()
            ->reject(fn (string $term) => self::isNegationTerm($term))
            ->values()
            ->all();
    }

    public function scopeConsented($query)
    {
        return $query->whereNotNull('consented_at');
    }

    /**
     * Ho so da duoc dong y cua mot nguoi dung, hoac null. Bon noi goi can dung dung
     * truy van nay, nen luat "chi doc khi co dong y" nam tai mot cho duy nhat.
     */
    public static function consentedFor(int $userId): ?self
    {
        return static::where('ma_nd', $userId)->consented()->first();
    }
}
