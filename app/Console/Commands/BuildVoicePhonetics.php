<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\VoicePhonetic;
use App\Support\VietnameseText;
use App\Support\VoicePhonetics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BuildVoicePhonetics extends Command
{
    protected $signature = 'voice:build-phonetics';

    protected $description = 'Dựng lại index phiên âm cho tìm kiếm bằng giọng nói';

    /** 6 cột x 500 dòng = 3.000 tham số, trong giới hạn của cả MySQL và SQLite. */
    private const INSERT_CHUNK = 500;

    public function handle(): int
    {
        $minHits = max(1, (int) config('search.voice.phonetic_min_hits', 3));

        [$rows, $tokens] = $this->collectFromProducts();

        foreach (Category::pluck('name') as $name) {
            $this->addRow($rows, (string) $name, 'category', 1);
        }

        foreach ($tokens as $folded => $token) {
            if ($token['hits'] < $minHits) {
                continue;
            }

            // Dạng khách đọc được, không phải dạng đã lột dấu: "thuốc" dễ đọc hơn
            // "thuoc" khi nó được điền vào ô tìm kiếm.
            arsort($token['forms']);

            $this->addRow($rows, (string) array_key_first($token['forms']), 'token', $token['hits']);
        }

        // Transaction quanh cả truncate + insert: một lần dựng thất bại giữa đường
        // để lại index rỗng, và index rỗng nghĩa là mọi lượt voice rơi xuống AI.
        DB::transaction(function () use ($rows) {
            VoicePhonetic::query()->delete();

            foreach (array_chunk(array_values($rows), self::INSERT_CHUNK) as $chunk) {
                VoicePhonetic::insert($chunk);
            }
        });

        $this->info(sprintf('Đã dựng %d cụm phiên âm.', count($rows)));

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array{hits: int, forms: array<string, int>}>}
     */
    private function collectFromProducts(): array
    {
        $rows = [];
        $tokens = [];

        // cursor(): catalog đã 3.146 dòng và chỉ tăng, nên không nạp hết vào bộ nhớ.
        Product::select('MaSP', 'name')->cursor()->each(function (Product $product) use (&$rows, &$tokens): void {
            $name = (string) $product->name;

            $this->addRow($rows, $name, 'product', 1);

            foreach (preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $folded = VietnameseText::fold($word);

                if ($folded === '' || mb_strlen($folded) < 2) {
                    continue;
                }

                $tokens[$folded]['hits'] = ($tokens[$folded]['hits'] ?? 0) + 1;
                $tokens[$folded]['forms'][$word] = ($tokens[$folded]['forms'][$word] ?? 0) + 1;
            }
        });

        return [$rows, $tokens];
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     */
    private function addRow(array &$rows, string $term, string $kind, int $weight): void
    {
        $key = VoicePhonetics::key($term);
        $folded = VietnameseText::fold($term);

        if ($key === '' || $folded === '') {
            return;
        }

        // Khoá gộp theo (phonetic_key, term_folded) — đúng cặp unique của bảng, nên
        // insert không bao giờ đụng constraint.
        $dedupe = $key . "\0" . $folded;

        if (isset($rows[$dedupe])) {
            $rows[$dedupe]['weight'] = max($rows[$dedupe]['weight'], $weight);

            return;
        }

        $rows[$dedupe] = [
            'phonetic_key' => $key,
            'block_key' => VoicePhonetics::blockKey($key),
            'term' => mb_substr($term, 0, 191),
            'term_folded' => mb_substr($folded, 0, 191),
            'kind' => $kind,
            'weight' => $weight,
        ];
    }
}
