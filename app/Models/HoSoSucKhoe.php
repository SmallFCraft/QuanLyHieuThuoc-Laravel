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
        // Dữ liệu sức khoẻ là nhạy cảm: mã hoá khi lưu, giải mã chỉ khi đọc.
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
     * Không thứ gì trong hồ sơ này được phép ảnh hưởng đến sàng lọc hoặc kiểm tra
     * an toàn trước khi người đã đồng ý rõ ràng.
     */
    public function hasConsented(): bool
    {
        return $this->consented_at !== null;
    }

    /**
     * Riêng biệt với hasConsented(): đồng ý cho dược sĩ đọc hồ sơ không phải là đồng ý
     * gửi dữ liệu đó đến một nhà cung cấp AI.
     */
    public function hasAiConsent(): bool
    {
        return $this->ai_consent_at !== null;
    }

    /**
     * Những gì người gõ vào trường tag để có nghĩa "không có gì khai báo".
     *
     * Không phải tiền sử y khoa: một chữ "Không" giữ vẫn trong danh sách dị ứng làm
     * knownTerms() không còn rỗng, và điều đó làm SangLocYTeService::isStepCoveredByProfile()
     * bỏ qua bước 4 của trợ lý — nên sàng lọc không bao giờ hỏi về bệnh mạn tính, dị ứng,
     * có thai hay cho con bú, để rồi một vòng hỏi làm rõ hỏi thay.
     *
     * Đồng thời giữ cột đã mã hoá sạch sẽ, vì AnToanThuocService đọc `allergies` trực tiếp
     * và quay về so khớp chuỗi con với tên sản phẩm cho từ khoá từ 4 ký tự trở lên.
     * "Không" fold ra chuỗi 5 ký tự, nên chỉ cách một tên sản phẩm xui là chặn giỏ hàng
     * với một cảnh báo dị ứng vô cơ.
     */
    public const NEGATION_TERMS = [
        'khong', 'khong co', 'khong co gi', 'khong bi gi', 'ko', 'ko co', 'ko co gi',
        'k', 'kg', 'chua', 'chua co', 'chang co', 'no', 'none', 'nothing', 'nil',
        'n a', 'na', 'trong', 'khong biet', '0', 'x',
    ];

    /**
     * fold() loại dấu câu, nên "N/A", "N.A." và "-" đều hoặc rơi vào danh sách trên
     * hoặc fold ra chuỗi rỗng.
     */
    public static function isNegationTerm(string $term): bool
    {
        $folded = VietnameseText::fold($term);

        return $folded === '' || in_array($folded, self::NEGATION_TERMS, true);
    }

    /**
     * Danh sách đã làm phẳng, viết thường, của mỗi từ khoá mà các kiểm tra an toàn
     * dùng để so khớp.
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
     * Hồ sơ đã được đồng ý của một người dùng, hoặc null. Bốn nơi gọi cần đúng dùng
     * truy vấn này, nên luật "chỉ đọc khi có đồng ý" nằm tại một chỗ duy nhất.
     */
    public static function consentedFor(int $userId): ?self
    {
        return static::where('ma_nd', $userId)->consented()->first();
    }
}
