<?php

namespace App\Models;

use App\Support\VietnameseText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Một từ khoá khách đã tìm, kèm số lần.
 *
 * Nguồn cho khối "Xu hướng tìm kiếm". Cố ý không lưu gì gắn với một người: bảng
 * chỉ đếm từ khoá, nên không có gì để lộ nếu bị đọc.
 */
class SearchQuery extends Model
{
    public const CACHE_KEY = 'search.trending';

    /** Bảng chỉ có last_searched_at; created_at/updated_at không nói thêm điều gì. */
    public $timestamps = false;

    protected $fillable = ['term_folded', 'term', 'hits', 'last_searched_at'];

    protected $casts = [
        'hits' => 'integer',
        'last_searched_at' => 'datetime',
    ];

    /**
     * Đếm thêm một lượt cho từ khoá này. Trả về false nếu từ khoá bị loại.
     *
     * Bốn cổng chặn, vì pill xu hướng là chữ do khách gõ được đưa lên trang chủ:
     * fold ra rỗng (chuỗi rác), chỉ một từ ngắn dưới 2 ký tự, chuỗi dài bất thường,
     * và từ trong blacklist.
     */
    public static function record(?string $term): bool
    {
        $term = trim(preg_replace('/\s+/u', ' ', (string) $term) ?? '');
        $folded = VietnameseText::fold($term);

        // 60 ký tự: từ khoá thật dài nhất trong catalog là tên thuốc đầy đủ (~45).
        // Dài hơn thì đó là đoạn văn dán vào, hoặc payload — không phải xu hướng.
        if ($folded === '' || mb_strlen($term) > 60 || Product::searchWords($term) === []) {
            return false;
        }

        if (self::isBlacklisted($folded)) {
            return false;
        }

        // firstOrCreate, không phải đọc-rồi-ghi: hai request song song cùng một từ khoá
        // mới đều thấy bảng trống rồi cùng insert, và bên thua ăn lỗi unique term_folded
        // — thành 500 trên trang kết quả đã render xong. Bên trong nó là createOrFirst,
        // có bắt UniqueConstraintViolationException và đọc lại dòng bên kia vừa ghi.
        $row = self::firstOrCreate(
            ['term_folded' => $folded],
            ['term' => $term, 'hits' => 1, 'last_searched_at' => now()],
        );

        if (! $row->wasRecentlyCreated) {
            $row->increment('hits', 1, ['last_searched_at' => now()]);
        }

        Cache::forget(self::CACHE_KEY);

        return true;
    }

    /**
     * Từ khoá xu hướng để hiện pill, đã đủ số lượng.
     *
     * Log thật xếp trước; nếu chưa đủ thì bù bằng config('search.trending.seed')
     * để panel không bao giờ trống. Cache 10 phút — panel nhúng vào mọi trang.
     *
     * ponytail: hits là lũy kế, cửa sổ 30 ngày lọc theo last_searched_at. Nên một
     * từ khoá cũ nhiều hits vẫn xếp trên nếu tháng này còn người tìm. Muốn đúng
     * cửa sổ thì cần một dòng mỗi lượt tìm + job dọn; chưa cần ở lượng traffic này.
     *
     * @return Collection<int, string>
     */
    public static function trending(?int $limit = null): Collection
    {
        $limit = $limit ?? (int) config('search.trending.limit', 8);

        return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), function () use ($limit) {
            $logged = self::query()
                ->where('last_searched_at', '>=', now()->subDays((int) config('search.trending.window_days', 30)))
                ->where('hits', '>=', (int) config('search.trending.min_hits', 2))
                ->orderByDesc('hits')
                ->orderByDesc('last_searched_at')
                ->limit($limit)
                ->pluck('term');

            if ($logged->count() >= $limit) {
                return $logged->values();
            }

            // Bỏ mồi trùng với từ khoá thật, so trên dạng đã fold để "Vitamin C"
            // không hiện hai lần cạnh nhau.
            $loggedFolded = $logged->map(fn (string $term) => VietnameseText::fold($term))->all();

            $seed = collect(config('search.trending.seed', []))
                ->reject(fn (string $term) => in_array(VietnameseText::fold($term), $loggedFolded, true));

            return $logged->concat($seed)->take($limit)->values();
        });
    }

    /**
     * Dùng lại blacklist của chatbot: cùng một mục đích — chặn chữ tục do khách gõ
     * lọt ra giao diện công khai — nên hai chỗ phải cùng một danh sách.
     */
    private static function isBlacklisted(string $folded): bool
    {
        foreach (config('blacklists.chatbot', []) as $bad) {
            if (VietnameseText::containsWord($folded, $bad)) {
                return true;
            }
        }

        return false;
    }
}
