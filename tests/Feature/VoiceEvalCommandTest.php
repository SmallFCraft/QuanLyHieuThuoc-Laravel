<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Thước đo của tính năng phải chạy được như một lệnh, không phải một file nháp ở
 * gốc repo. Test này khoá lại chính phép đếm — nếu ai đó đổi định nghĩa "đạt" thì
 * con số trong commit message sau này không còn so được với con số trước.
 */
class VoiceEvalCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['search.voice.ai' => false, 'search.voice.phonetic_min_hits' => 1]);

        $category = Category::create(['name' => 'Thuốc giảm đau, hạ sốt']);

        Product::create([
            'MaDM' => $category->MaDM,
            'name' => 'Thuốc hạ sốt Hapacol 500',
            'ingredients' => 'Paracetamol 500mg',
            'price' => 50000,
            'expiration_date' => now()->addYears(2),
            'stock_quantity' => 30,
            'reorder_point' => 10,
            'batch_number' => 'VN-1',
        ]);

        $this->artisan('voice:build-phonetics');
    }

    public function test_it_reports_the_three_metrics(): void
    {
        $this->artisan('voice:eval --json --floor=0 --lies=999')
            ->assertExitCode(0);

        $payload = json_decode((string) file_get_contents(storage_path('app/voice-eval.json')), true);

        $this->assertSame(94, $payload['total']);
        $this->assertIsInt($payload['coverage']);
        $this->assertIsInt($payload['lies']);
        $this->assertIsInt($payload['confident_zero']);
        $this->assertArrayHasKey('exact', $payload['by_category']);
    }

    public function test_it_fails_when_coverage_is_below_the_floor(): void
    {
        $this->artisan('voice:eval --floor=95 --lies=999')->assertExitCode(1);
    }

    public function test_it_fails_when_lies_exceed_the_ceiling(): void
    {
        $this->artisan('voice:eval --floor=0 --lies=0')->assertExitCode(1);
    }
}
