<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Endpoint công khai: ô tìm kiếm ở header dùng được cho cả khách chưa đăng nhập,
 * nên nó phải tự bảo vệ bằng validate và throttle chứ không bằng `auth`.
 */
class VoiceSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        RateLimiter::clear('voice-normalize:127.0.0.1');

        // phonetic_min_hits=3 là ngưỡng đo trên catalog 3.146 dòng. Fixture chỉ có
        // một sản phẩm nên ở ngưỡng đó index KHÔNG sinh dòng `token` nào, và tầng
        // phiên âm không có gì để khớp — đo được ở Task 3: source về `raw` thay vì
        // `phonetic`. Hạ ngưỡng cho fixture để index có cùng hình dạng như index thật.
        config(['search.voice.phonetic_min_hits' => 1]);

        $category = Category::create(['name' => 'Thuốc giảm đau, hạ sốt']);

        Product::create([
            'MaDM' => $category->MaDM,
            'name' => 'Thuốc hạ sốt Hapacol 500',
            'ingredients' => 'Paracetamol 500mg',
            'price' => 50000,
            'expiration_date' => now()->addYears(2),
            'stock_quantity' => 30,
            'reorder_point' => 10,
            'batch_number' => 'VS-1',
        ]);

        $this->artisan('voice:build-phonetics');
    }

    public function test_it_returns_the_normalised_query_for_a_guest(): void
    {
        $response = $this->postJson(route('voice.normalize'), [
            'alternatives' => ['Thuốc hạ sốt Hapacol 500'],
        ]);

        $response->assertOk()
            ->assertJsonStructure(['text', 'heard', 'source', 'confident'])
            ->assertJsonPath('source', 'exact')
            ->assertJsonPath('text', 'Thuốc hạ sốt Hapacol 500');
    }

    public function test_it_fixes_regional_pronunciation(): void
    {
        $response = $this->postJson(route('voice.normalize'), [
            'alternatives' => ['thuấc hạ sốc'],
        ]);

        $response->assertOk()->assertJsonPath('heard', 'thuấc hạ sốc');
        $this->assertStringContainsString('thuốc', mb_strtolower($response->json('text')));
    }

    /**
     * PHPUnit 12 KHÔNG đọc annotation docblock `@dataProvider` nữa — test sẽ chạy một
     * lần với 0 tham số và ném ArgumentCountError. Attribute mới là cách duy nhất;
     * đây cũng là lối 6 file test khác trong repo đang dùng.
     */
    #[DataProvider('invalidPayloads')]
    public function test_it_rejects_bad_payloads(array $payload): void
    {
        $this->postJson(route('voice.normalize'), $payload)->assertStatus(422);
    }

    public static function invalidPayloads(): array
    {
        return [
            'thiếu khoá' => [[]],
            'mảng rỗng' => [['alternatives' => []]],
            'không phải mảng' => [['alternatives' => 'thuốc hạ sốt']],
            'quá 3 phần tử' => [['alternatives' => ['a', 'b', 'c', 'd']]],
            'phần tử không phải chuỗi' => [['alternatives' => [['nested']]]],
            'quá 200 ký tự' => [['alternatives' => [str_repeat('a', 201)]]],
        ];
    }

    public function test_it_throttles_at_thirty_per_minute(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson(route('voice.normalize'), ['alternatives' => ["câu số {$i}"]])
                ->assertOk();
        }

        $this->postJson(route('voice.normalize'), ['alternatives' => ['một câu nữa']])
            ->assertStatus(429);
    }

    /** Bucket riêng: voice dùng hết hạn mức không được làm autocomplete chết. */
    public function test_its_throttle_bucket_is_separate_from_autocomplete(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson(route('voice.normalize'), ['alternatives' => ["câu số {$i}"]]);
        }

        $this->getJson(route('products.suggestions', ['q' => 'hạ sốt']))->assertOk();
    }

    public function test_the_kill_switch_removes_the_endpoint(): void
    {
        config(['search.voice.enabled' => false]);

        $this->postJson(route('voice.normalize'), ['alternatives' => ['thuốc hạ sốt']])
            ->assertStatus(404);
    }

    /**
     * Permissions-Policy hiện đặt microphone=() — danh sách RỖNG, tức không origin
     * nào được dùng mic, kể cả chính trang này. Không nới thì Web Speech bị từ chối
     * ở tầng policy và tính năng chết im lặng.
     */
    public function test_the_page_allows_its_own_origin_to_request_the_microphone(): void
    {
        $policy = $this->get(route('home'))->headers->get('Permissions-Policy');

        $this->assertStringContainsString('microphone=(self)', $policy);
        // Nới đúng một quyền: camera và geolocation không đổi.
        $this->assertStringContainsString('camera=()', $policy);
        $this->assertStringContainsString('geolocation=()', $policy);
    }

    public function test_the_header_renders_the_microphone_button(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-voice-search', false)
            ->assertSee('PharmacyVoiceLang', false);
    }

    /** Chuỗi giao diện đến từ Blade; thiếu khoá là một nút không có nhãn. */
    public function test_every_interface_string_exists(): void
    {
        foreach (['voice_start', 'voice_listening', 'voice_working', 'voice_heard',
                  'voice_undo', 'voice_error_not_allowed', 'voice_error_no_speech',
                  'voice_error_network', 'voice_error_generic'] as $key) {
            $this->assertNotSame(
                "messages.{$key}",
                __("messages.{$key}"),
                "thiếu khoá lang: messages.{$key}"
            );
        }
    }
}
