<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\VoiceSearchNormalizer;
use Illuminate\Console\Command;

/**
 * Điểm số của tìm kiếm bằng giọng nói trên tập 94 ca thật.
 *
 * Vì sao là một lệnh chứ không phải một test: đây là số ĐO, không phải điều kiện
 * đúng/sai. Nó đi xuống khi catalog đổi mà không có ai sửa gì cả, nên để trong CI
 * dạng assert là làm suite đỏ vì lý do sai. `--floor` và `--lies` để dùng như một
 * cổng khi cần: mặc định chính là số nền đo ngày 2026-09-02.
 *
 * THƯỚC ĐO — xem thêm đầu tests/fixtures/voice-search-corpus.php:
 *   coverage : |A ∩ B| >= |B| / 2, với A = MaSP của normalize, B = MaSP của intended
 *   lies     : confident === true mà ca đó KHÔNG đạt — hệ thống nói dối khách
 *   conf_zero: confident === true mà catalog trả 0 sản phẩm
 */
class EvalVoiceSearch extends Command
{
    protected $signature = 'voice:eval
        {--floor=56 : Số ca đạt tối thiểu, dưới ngưỡng thì lệnh thoát 1}
        {--lies=14 : Số ca "confident mà sai" tối đa}
        {--json : Ghi kết quả vào storage/app/voice-eval.json}';

    protected $description = 'Đo tìm kiếm bằng giọng nói trên tập 94 ca thật';

    public function handle(VoiceSearchNormalizer $normalizer): int
    {
        // Tầng AI tắt: nó không tất định nên số đo sẽ nhảy giữa hai lần chạy, và
        // hai tầng rẻ là phần duy nhất repo này kiểm soát được.
        config(['search.voice.ai' => false]);

        $corpus = require base_path('tests/fixtures/voice-search-corpus.php');
        $byCategory = [];
        $coverage = 0;
        $lies = 0;
        $confidentZero = 0;
        $failures = [];

        foreach ($corpus as $case) {
            // Instance mới mỗi ca: memo $wordInName sống trong một lượt normalize(),
            // dùng lại instance là đo cache chứ không đo tính năng.
            $result = app(VoiceSearchNormalizer::class)->normalize($case['alternatives']);
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
        if (trim($query) === '' || Product::searchWords($query) === []) {
            return [];
        }

        return Product::notExpired()->storefrontSearch($query)->limit(500)->pluck('MaSP')->all();
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

        // Nhóm nói dối lên trước: đó là nhóm hại khách, không phải nhóm trượt.
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
