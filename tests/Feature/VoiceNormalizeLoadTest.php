<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Bốn bất biến về tải, không phải bốn số đo hiệu năng.
 *
 * PHPUnit chạy tuần tự trong một process nên nó KHÔNG chứng minh được "ngàn người
 * dùng cùng lúc" — cái đó cần tests/load/voice-normalize.k6.js chạy sau nginx +
 * php-fpm. Cái mà file này khoá được: số lời gọi AI, số truy vấn, và ngưỡng thời
 * gian của mỗi lượt.
 */
class VoiceNormalizeLoadTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $catalogNames = [
        'Thuốc hạ sốt Hapacol 500',
        'Thuốc hạ sốt Efferalgan 500',
        'Thuốc giảm đau Panadol Extra',
        'Men vi sinh Enterogermina',
        'Nước muối sinh lý Fysoline',
        'Kem chống nắng Anessa',
        'Viên uống Vitamin C Cebion',
        'Khẩu trang y tế 4 lớp',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->clearThrottle();

        // phonetic_min_hits=3 là ngưỡng đo trên catalog 3.146 dòng. Fixture chỉ có
        // một sản phẩm nên ở ngưỡng đó index KHÔNG sinh dòng `token` nào, và tầng
        // phiên âm không có gì để khớp — đo được ở Task 3: source về `raw` thay vì
        // `phonetic`. Hạ ngưỡng cho fixture để index có cùng hình dạng như index thật.
        config(['search.voice.phonetic_min_hits' => 1]);

        $category = Category::create(['name' => 'Thuốc giảm đau, hạ sốt']);

        foreach ($this->catalogNames as $i => $name) {
            Product::create([
                'MaDM' => $category->MaDM,
                'name' => $name,
                'ingredients' => 'Paracetamol 500mg',
                'price' => 50000,
                'expiration_date' => now()->addYears(2),
                'stock_quantity' => 30,
                'reorder_point' => 10,
                'batch_number' => "VL-{$i}",
            ]);
        }

        $this->artisan('voice:build-phonetics');
    }

    /**
     * Khoá thật mà ThrottleRequests dùng, không phải `voice-normalize:127.0.0.1`.
     *
     * `throttle:30,1,voice-normalize:` ghép prefix với resolveRequestSignature(), và
     * signature đó là sha1("{domain}|{ip}") vì ThrottleRequests::$shouldHashKeys mặc
     * định true. Đo được trong repo này: attempts('voice-normalize:127.0.0.1') = 0
     * trong khi attempts(khoá dưới đây) = 1. Clear sai khoá thì hai test dưới bắn quá
     * 30 request mỗi phút và nhận 429.
     */
    private function clearThrottle(): void
    {
        RateLimiter::clear('voice-normalize:'.sha1('|127.0.0.1'));
    }

    /**
     * Đường phiên âm phải phục vụ phần lớn lượt mà không gọi AI lần nào. Đây là bất
     * biến giữ cho tính năng chịu được tải: mỗi lượt trượt là một lời gọi tới một
     * provider đo được mất 18-26s.
     */
    public function test_the_phonetic_path_never_calls_the_ai_and_stays_fast(): void
    {
        $spoken = ['thuấc hạ sốc', 'mem vi xinh', 'nước muối xinh lý', 'kem chóng nắng'];
        $times = [];

        for ($i = 0; $i < 200; $i++) {
            $this->clearThrottle();

            $startedAt = microtime(true);

            $this->postJson(route('voice.normalize'), [
                'alternatives' => [$spoken[$i % count($spoken)]],
            ])->assertOk();

            $times[] = (microtime(true) - $startedAt) * 1000;
        }

        Http::assertNothingSent();

        sort($times);
        $p95 = $times[(int) floor(count($times) * 0.95)];

        $this->assertLessThan(150, $p95, "p95: {$p95}ms, kỳ vọng < 150ms");
    }

    /** Cache và lock: 100 người nói cùng một câu lạ chỉ đáng một lời gọi AI. */
    public function test_one_hundred_identical_utterances_cost_one_ai_call(): void
    {
        config(['search.voice.ai' => true]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'thuốc hạ sốt']]]])]);

        for ($i = 0; $i < 100; $i++) {
            $this->clearThrottle();

            $this->postJson(route('voice.normalize'), ['alternatives' => ['bơ bơ rin gì đó']])
                ->assertOk();
        }

        Http::assertSentCount(1);
    }

    /** Request bị throttle không được phép chạm tới tầng AI. */
    public function test_throttled_requests_never_reach_the_ai(): void
    {
        config(['search.voice.ai' => true]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'thuốc hạ sốt']]]])]);

        $throttled = 0;

        for ($i = 0; $i < 40; $i++) {
            // "bơ bơ rin gì đó N" chứ không phải "câu lạ số N": đo được, "số 0".."số 9"
            // khớp tầng phiên âm với token "sốt" của catalog fixture, nên 10 trong 30
            // lượt đầu không bao giờ tới AI và số lời gọi là 20 chứ không phải 30. Câu
            // dưới đây trượt cả hai tầng rẻ ở cả 40 lượt — đúng cái mà test cần.
            $status = $this->postJson(route('voice.normalize'), ['alternatives' => ["bơ bơ rin gì đó {$i}"]])
                ->getStatusCode();

            if ($status === 429) {
                $throttled++;
            }
        }

        $this->assertGreaterThan(0, $throttled, 'throttle phải chặn ít nhất một request');
        // 30 lượt đầu đi qua, mỗi lượt một câu khác nhau nên cache không che được.
        Http::assertSentCount(30);
    }

    /** Chống hồi quy N+1: đường không-AI là hai truy vấn có index, không hơn. */
    public function test_the_cheap_path_runs_a_bounded_number_of_queries(): void
    {
        DB::enableQueryLog();

        $this->postJson(route('voice.normalize'), ['alternatives' => ['thuấc hạ sốc']])->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Ngưỡng 6, không phải 2: nhóm middleware `web` tự đọc session và throttle.
        // Con số cần chặn là một truy vấn MỖI ứng viên phiên âm.
        $this->assertLessThan(
            6,
            count($queries),
            'số truy vấn: ' . count($queries) . ', kỳ vọng < 6'
        );
    }
}
