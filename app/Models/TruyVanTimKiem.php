<?php

namespace App\Models;

use App\Models\SanPham;
use App\Support\VietnameseText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Mot tu khoa khach da tim, kem so lan.
 *
 * Nguon cho khoi "Xu huong tim kiem". Co y khong luu gi gan voi mot nguoi: bang
 * chi dem tu khoa, nen khong co gi de lo neu bi doc.
 */
class TruyVanTimKiem extends Model
{
    public const CACHE_KEY = 'search.trending';

    /** Bang chi co last_searched_at; created_at/updated_at khong noi them dieu gi. */
    public $timestamps = false;

    protected $table = 'truy_van_tim_kiems';

    protected $fillable = ['term_folded', 'term', 'hits', 'last_searched_at'];

    protected $casts = [
        'hits' => 'integer',
        'last_searched_at' => 'datetime',
    ];

    /**
     * Dem them mot luot cho tu khoa nay. Tra ve false neu tu khoa bi loai.
     *
     * Bon cong chan, vi pill xu huong la chu do khach go duoc dua len trang chu:
     * fold ra rong (chuoi rac), chi mot tu ngan duoi 2 ky tu, chuoi dai bat thuong,
     * va tu trong blacklist.
     */
    public static function record(?string $term): bool
    {
        $term = trim(preg_replace('/\s+/u', ' ', (string) $term) ?? '');
        $folded = VietnameseText::fold($term);

        // 60 ky tu: tu khoa that dai nhat trong catalog la ten thuoc day du (~45).
        // Dai hon thi do la doan van dan vao, hoac payload — khong phai xu huong.
        if ($folded === '' || mb_strlen($term) > 60 || SanPham::searchWords($term) === []) {
            return false;
        }

        if (self::isBlacklisted($folded)) {
            return false;
        }

        // firstOrCreate, khong phai doc-roi-ghi: hai request song song cung mot tu khoa
        // moi deu thay bang trong roi cung insert, va ben thua an loi unique term_folded
        // — thanh 500 tren trang ket qua da render xong. Ben trong no la createOrFirst,
        // co bat UniqueConstraintViolationException va doc lai dong ben kia vua ghi.
        $dong = self::firstOrCreate(
            ['term_folded' => $folded],
            ['term' => $term, 'hits' => 1, 'last_searched_at' => now()],
        );

        if (! $dong->wasRecentlyCreated) {
            $dong->increment('hits', 1, ['last_searched_at' => now()]);
        }

        Cache::forget(self::CACHE_KEY);

        return true;
    }

    /**
     * Tu khoa xu huong de hien pill, da du so luong.
     *
     * Log that xep truoc; neu chua du thi bu bang config('search.trending.seed')
     * de panel khong bao gio trong. Cache 10 phut — panel nhung vao moi trang.
     *
     * ponytail: hits la luy ke, cua so 30 ngay loc theo last_searched_at. Nen mot
     * tu khoa cu nhieu hits van xep tren neu thang nay con nguoi tim. Muon dung
     * cua so thi can mot dong moi luot tim + job don; chua can o luong traffic nay.
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

            // Bo moi trung voi tu khoa that, so tren dang da fold de "Vitamin C"
            // khong hien hai lan canh nhau.
            $loggedFolded = $logged->map(fn (string $term) => VietnameseText::fold($term))->all();

            $seed = collect(config('search.trending.seed', []))
                ->reject(fn (string $term) => in_array(VietnameseText::fold($term), $loggedFolded, true));

            return $logged->concat($seed)->take($limit)->values();
        });
    }

    /**
     * Dung lai blacklist cua chatbot: cung mot muc dich — chan chu tuc do khach go
     * lot ra giao dien cong khai — nen hai cho phai cung mot danh sach.
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
