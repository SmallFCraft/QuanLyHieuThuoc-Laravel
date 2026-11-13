<?php

namespace App\Console\Commands;

use App\Models\SanPham;
use App\Services\ChuanHoaTimKiemGiongNoi;
use Illuminate\Console\Command;

/**
 * Diem so cua tim kiem bang giong noi tren tap 94 ca that.
 *
 * Vi sao la mot lenh chu khong phai mot test: day la so DO, khong phai dieu kien
 * dung/sai. No di xuong khi catalog doi ma khong co ai sua gi ca, nen de trong CI
 * dang assert la lam suite do vi ly do sai. `--floor` va `--lies` de dung nhu mot
 * cong khi can: mac dinh chinh la so nen do ngay 2026-09-02.
 *
 * THUOC DO — xem them dau tests/fixtures/voice-search-corpus.php:
 *   coverage : |A ∩ B| >= |B| / 2, voi A = ma_sp cua normalize, B = ma_sp cua intended
 *   lies     : confident === true ma ca do KHONG dat — he thong noi doi khach
 *   conf_zero: confident === true ma catalog tra 0 san pham
 */
class DanhGiaTimKiemGiongNoi extends Command
{
    protected $signature = 'voice:eval
        {--floor=56 : Số ca đạt tối thiểu, dưới ngưỡng thì lệnh thoát 1}
        {--lies=14 : Số ca "confident mà sai" tối đa}
        {--json : Ghi kết quả vào storage/app/voice-eval.json}';

    protected $description = 'Đo tìm kiếm bằng giọng nói trên tập 94 ca thật';

    public function handle(ChuanHoaTimKiemGiongNoi $normalizer): int
    {
        // Tang AI tat: no khong tat dinh nen so do se nhay giua hai lan chay, va
        // hai tang re la phan duy nhat repo nay kiem soat duoc.
        config(['search.voice.ai' => false]);

        $corpus = require base_path('tests/fixtures/voice-search-corpus.php');
        $byCategory = [];
        $coverage = 0;
        $lies = 0;
        $confidentZero = 0;
        $failures = [];

        foreach ($corpus as $case) {
            // Instance moi moi ca: memo $wordInName song trong mot luot normalize(),
            // dung lai instance la do cache chu khong do tinh nang.
            $result = app(ChuanHoaTimKiemGiongNoi::class)->chuanHoa($case['alternatives']);
            $category = $case['category'];
            $byCategory[$category] ??= ['n' => 0, 'ok' => 0];
            $byCategory[$category]['n']++;

            $got = $this->ids($result['text']);

            if ($case['intended'] === null) {
                $ok = $result['confident'] === false;
            } else {
                $want = $this->ids($case['intended']);
                $ok = $want !== [] && count(array_intersect($got, $want)) >= count($want) / 2;
            }

            if ($ok) {
                $coverage++;
                $byCategory[$category]['ok']++;
            } else {
                if ($result['confident']) {
                    $lies++;
                }

                $failures[] = [
                    'said' => $case['said'],
                    'got' => $result['text'],
                    'want' => $case['intended'] ?? '(vô nghĩa)',
                    'source' => $result['source'],
                    'confident' => $result['confident'],
                ];
            }

            if ($result['confident'] && $got === []) {
                $confidentZero++;
            }
        }

        $this->render($corpus, $byCategory, $coverage, $lies, $confidentZero, $failures);

        $floor = (int) $this->option('floor');
        $ceiling = (int) $this->option('lies');

        if ($coverage < $floor) {
            $this->error("coverage {$coverage} dưới ngưỡng {$floor}");

            return self::FAILURE;
        }

        if ($lies > $ceiling) {
            $this->error("lies {$lies} vượt ngưỡng {$ceiling}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array<int, int> */
    private function ids(string $query): array
    {
        if (trim($query) === '' || SanPham::searchWords($query) === []) {
            return [];
        }

        return SanPham::notExpired()->storefrontSearch($query)->limit(500)->pluck('ma_sp')->all();
    }

    private function render(
        array $corpus,
        array $byCategory,
        int $coverage,
        int $lies,
        int $confidentZero,
        array $failures
    ): void {
        ksort($byCategory);

        $this->table(
            ['category', 'n', 'ok', 'tỉ lệ'],
            collect($byCategory)->map(fn (array $row, string $key) => [
                $key,
                $row['n'],
                $row['ok'],
                round($row['ok'] / $row['n'] * 100) . '%',
            ])->values()->all()
        );

        $total = count($corpus);
        $this->line("coverage      = {$coverage}/{$total}");
        $this->line("lies          = {$lies}");
        $this->line("confident + 0 = {$confidentZero}");

        // Nhom noi doi len truoc: do la nhom hai khach, khong phai nhom truot.
        usort($failures, fn (array $a, array $b) => ($b['confident'] <=> $a['confident']));

        foreach (array_slice($failures, 0, 15) as $failure) {
            $this->line(sprintf(
                '  %-30s -> %-30s (đúng: %s) %s%s',
                $failure['said'],
                $failure['got'],
                $failure['want'],
                $failure['source'],
                $failure['confident'] ? ' CONFIDENT' : ''
            ));
        }

        if ($this->option('json')) {
            file_put_contents(
                storage_path('app/voice-eval.json'),
                json_encode([
                    'total' => $total,
                    'coverage' => $coverage,
                    'lies' => $lies,
                    'confident_zero' => $confidentZero,
                    'by_category' => $byCategory,
                    'failures' => $failures,
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
        }
    }
}
