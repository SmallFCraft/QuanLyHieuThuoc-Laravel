<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\SanPham;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Endpoint công khai: ô tìm kiếm ở header dùng được cho cả khách chưa đăng nhập,
 * nên nó phải tự bảo vệ bằng validate và throttle chứ không bằng `auth`.
 */
class TimKiemGiongNoiTest extends TestCase
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

        $danhMuc = DanhMuc::create(['ten' => 'Thuốc giảm đau, hạ sốt']);

        SanPham::create(['ma_dm' => $danhMuc->ma_dm,
            'ten' => 'Thuốc hạ sốt Hapacol 500',
            'thanh_phan' => 'Paracetamol 500mg',
            'gia' => 50000,
            'ngay_het_han' => now()->addYears(2),
            'so_luong_ton' => 30,
            'muc_canh_bao' => 10,
            'so_lo' => 'VS-1',
        ]);

        $this->artisan('voice:build-phonetics');
    }

    public function test_tra_truy_van_da_chuan_hoa_cho_khach_vo_danh(): void
    {
        $response = $this->postJson(route('tim-kiem-giong-noi.chuan-hoa'), [
            'alternatives' => ['Thuốc hạ sốt Hapacol 500'],
        ]);

        $response->assertOk()
            ->assertJsonStructure(['text', 'heard', 'source', 'confident'])
            ->assertJsonPath('source', 'exact')
            ->assertJsonPath('text', 'Thuốc hạ sốt Hapacol 500');
    }

    public function test_sua_phat_am_vung_mien(): void
    {
        $response = $this->postJson(route('tim-kiem-giong-noi.chuan-hoa'), [
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
    public function test_tu_choi_payload_khong_hop_le(array $payload): void
    {
        $this->postJson(route('tim-kiem-giong-noi.chuan-hoa'), $payload)->assertStatus(422);
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

    /**
     * Byte UTF-8 hỏng phải bị chặn ở biên, không được thành 500.
     *
     * $this->post, KHÔNG phải postJson: postJson chạy payload qua json_encode(), và
     * json_encode() trả false trên byte hỏng nên byte đó không bao giờ rời máy test —
     * đo 2026-09-02, postJson cho 422 với thông báo "alternatives là bắt buộc", tức là
     * pass vì key rỗng, không phải vì encoding. Form POST là đường duy nhất trong bộ
     * test đưa được byte thô tới controller, và đo được nó cho 500:
     * InvalidArgumentException "Malformed UTF-8 characters".
     */
    public function test_tu_choi_utf8_sai_dinh_dang(): void
    {
        $response = $this->post(
            route('tim-kiem-giong-noi.chuan-hoa'),
            ['alternatives' => ["\xC3\x28 abc"]],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(422);
    }

    /** Khoá `alternatives` không tuần tự thì `heard` rỗng mà `text` có chữ. */
    public function test_chuan_hoa_khoa_khong_lien_tiep(): void
    {
        $response = $this->postJson(route('tim-kiem-giong-noi.chuan-hoa'), [
            'alternatives' => ['a' => 'thuốc hạ sốt'],
        ]);

        $response->assertOk()->assertJsonPath('heard', 'thuốc hạ sốt');
    }

    public function test_loai_ky_tu_dieu_khien(): void
    {
        $response = $this->postJson(route('tim-kiem-giong-noi.chuan-hoa'), [
            'alternatives' => ["\x07thuốc hạ sốt\x01"],
        ]);

        $response->assertOk();
        $this->assertSame('thuốc hạ sốt', $response->json('heard'));

        $this->assertSame(
            'thuốc hạ sốt',
            $this->postJson(route('tim-kiem-giong-noi.chuan-hoa'), ['alternatives' => ["thuốc\x07hạ sốt"]])->json('heard'),
            'Ký tự điều khiển GIỮA câu: regex hai đầu của trimPunctuation() không với tới.'
        );
    }

    public function test_gioi_han_ba_muoi_luot_moi_phut(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson(route('tim-kiem-giong-noi.chuan-hoa'), ['alternatives' => ["câu số {$i}"]])
                ->assertOk();
        }

        $this->postJson(route('tim-kiem-giong-noi.chuan-hoa'), ['alternatives' => ['một câu nữa']])
            ->assertStatus(429);
    }

    /** Bucket riêng: voice dùng hết hạn mức không được làm autocomplete chết. */
    public function test_nhom_gioi_han_rieng_voi_tu_dong_goi_y(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson(route('tim-kiem-giong-noi.chuan-hoa'), ['alternatives' => ["câu số {$i}"]]);
        }

        $this->getJson(route('san-pham.goi-y', ['q' => 'hạ sốt']))->assertOk();
    }

    public function test_cong_tac_tat_go_endpoint(): void
    {
        config(['search.voice.enabled' => false]);

        $this->postJson(route('tim-kiem-giong-noi.chuan-hoa'), ['alternatives' => ['thuốc hạ sốt']])
            ->assertStatus(404);
    }

    /**
     * Permissions-Policy hiện đặt microphone=() — danh sách RỖNG, tức không origin
     * nào được dùng mic, kể cả chính trang này. Không nới thì Web Speech bị từ chối
     * ở tầng policy và tính năng chết im lặng.
     */
    public function test_trang_cho_phep_origin_cua_minh_yeu_cau_microphone(): void
    {
        $policy = $this->get(route('trang-chu'))->headers->get('Permissions-Policy');

        $this->assertStringContainsString('microphone=(self)', $policy);
        // Nới đúng một quyền: camera và geolocation không đổi.
        $this->assertStringContainsString('camera=()', $policy);
        $this->assertStringContainsString('geolocation=()', $policy);
    }

    public function test_dau_trang_render_nut_microphone(): void
    {
        $this->get(route('trang-chu'))
            ->assertOk()
            ->assertSee('data-voice-search', false)
            ->assertSee('PharmacyVoiceLang', false);
    }

    /** Chuỗi giao diện đến từ Blade; thiếu khoá là một nút không có nhãn. */
    public function test_moi_chu_giao_dien_deu_ton_tai(): void
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
