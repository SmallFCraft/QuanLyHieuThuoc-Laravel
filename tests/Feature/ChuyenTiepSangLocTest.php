<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\TinNhanChat;
use App\Models\SanPham;
use App\Models\NguoiDung;
use App\Services\ChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChuyenTiepSangLocTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // TriThucYTeSeeder maps contraindications onto existing categories.
        DanhMuc::factory()->create(['ten' => 'Thuốc giảm đau - Hạ sốt']);
        $this->seed(\Database\Seeders\TriThucYTeSeeder::class);
    }

    protected function activeUser(): NguoiDung
    {
        return NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active',
        ]);
    }

    protected function fakeProviderReply(string $content): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                ]],
            ]),
        ]);
    }

    protected function fakeProviderFunctionCall(string $name, array $arguments): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'function_call' => [
                            'name' => $name,
                            'arguments' => json_encode($arguments),
                        ],
                    ],
                ]],
            ]),
        ]);
    }

    public function test_tin_nhan_trieu_chung_tra_ve_chuyen_tiep_sang_loc_khong_kem_san_pham(): void
    {
        $response = $this->actingAs($this->activeUser())->postJson('/api/chatbot/message', [
            'message' => 'tôi bị đau đầu 2 ngày',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('response_type', 'triage_handoff')
            ->assertJsonPath('triage_handoff.url', route('sang-loc.kiem-tra'))
            ->assertJsonPath('triage_handoff.label', 'Bắt đầu sàng lọc');

        $this->assertSame([], $response->json('products'));
        $this->assertSame([], $response->json('categories'));
        $this->assertTrue($response->json('show_disclaimer'));
    }

    public function test_tin_nhan_khan_cap_duoc_tra_loi_trong_tien_ich_ma_khong_chuyen_tiep(): void
    {
        $response = $this->actingAs($this->activeUser())->postJson('/api/chatbot/message', [
            'message' => 'đau ngực dữ dội, khó thở',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('response_type', 'urgent_health_consultation');

        $this->assertNull($response->json('triage_handoff'));
        $this->assertStringContainsString('bác sĩ', (string) $response->json('response'));
    }

    public function test_cau_hoi_san_pham_ro_rang_duoc_chuyen_cho_danh_muc(): void
    {
        SanPham::factory()->create(['ma_dm' => DanhMuc::where('ten', 'Thuốc giảm đau - Hạ sốt')->value('ma_dm'),
            'ten' => 'Paracetamol 500mg',
            'thanh_phan' => 'Paracetamol 500mg',
            'cach_dung' => 'Giảm đau, hạ sốt',
            'so_luong_ton' => 100,
            'ngay_het_han' => now()->addYear(),
        ]);

        $response = $this->actingAs($this->activeUser())->postJson('/api/chatbot/message', [
            'message' => 'có thuốc paracetamol không',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertNull($response->json('triage_handoff'));
        $this->assertNotEmpty($response->json('products'));
        $this->assertSame('Paracetamol 500mg', $response->json('products.0.name'));
    }

    public function test_loi_chao_khong_tra_ve_chuyen_tiep_sang_loc(): void
    {
        $this->fakeProviderReply('Dạ, em chào anh/chị ạ.');

        $response = $this->actingAs($this->activeUser())->postJson('/api/chatbot/message', [
            'message' => 'xin chào',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertNull($response->json('triage_handoff'));
        $this->assertSame([], $response->json('products'));
    }

    public function test_khach_chua_dang_nhap_khong_the_dung_chatbot(): void
    {
        $this->postJson('/api/chatbot/message', ['message' => 'tôi bị đau đầu 2 ngày'])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('require_login', true);
    }

    public function test_chuyen_tiep_sang_loc_duoc_luu_trong_sieu_du_lieu_tin_nhan(): void
    {
        $nguoiDung = $this->activeUser();

        $this->actingAs($nguoiDung)
            ->postJson('/api/chatbot/message', ['message' => 'tôi bị đau đầu 2 ngày'])
            ->assertOk();

        $assistantMessage = TinNhanChat::where('ma_nd', $nguoiDung->ma_nd)
            ->where('role', 'assistant')
            ->latest('ma_tn')
            ->firstOrFail();

        $this->assertSame('triage_handoff', $assistantMessage->metadata['response_type'] ?? null);
        $this->assertSame(
            route('sang-loc.kiem-tra'),
            $assistantMessage->metadata['triage_handoff']['url'] ?? null
        );
    }

    public function test_luoc_do_ham_cong_bo_bon_cong_cu(): void
    {
        $method = new \ReflectionMethod(ChatbotService::class, 'getFunctionSchema');
        $method->setAccessible(true);

        $names = array_column($method->invoke(app(ChatbotService::class)), 'name');

        $this->assertSame(
            ['search_products', 'search_categories', 'assess_symptoms', 'check_drug_safety'],
            $names
        );
    }

    public function test_loi_goi_cong_cu_danh_gia_trieu_chung_tra_ve_chuyen_tiep_thay_vi_chay_quy_trinh(): void
    {
        $this->fakeProviderFunctionCall('assess_symptoms', [
            'symptoms' => ['đau đầu'],
            'duration_days' => 2,
        ]);

        $response = $this->actingAs($this->activeUser())->postJson('/api/chatbot/message', [
            'message' => 'đau đầu nên dùng gì',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('response_type', 'triage_handoff')
            ->assertJsonPath('triage_handoff.url', route('sang-loc.kiem-tra'));

        $this->assertSame([], $response->json('products'));
    }

    public function test_loi_goi_cong_cu_kiem_tra_an_toan_thuoc_bao_san_pham_khong_xac_dinh(): void
    {
        $this->fakeProviderFunctionCall('check_drug_safety', [
            'product_name' => 'Khong Ton Tai 999mg',
        ]);

        $response = $this->actingAs($this->activeUser())->postJson('/api/chatbot/message', [
            'message' => 'đau đầu nên dùng gì',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('response_type', 'drug_safety_check');

        $this->assertStringContainsString('chưa thấy thuốc', (string) $response->json('response'));
        $this->assertSame([], $response->json('products'));
    }

    public function test_loi_goi_cong_cu_kiem_tra_an_toan_thuoc_kiem_tra_san_pham_thuc(): void
    {
        SanPham::factory()->create(['ma_dm' => DanhMuc::where('ten', 'Thuốc giảm đau - Hạ sốt')->value('ma_dm'),
            'ten' => 'Paracetamol 500mg',
            'thanh_phan' => 'Paracetamol 500mg',
            'cach_dung' => 'Giảm đau, hạ sốt',
            'so_luong_ton' => 100,
            'ngay_het_han' => now()->addYear(),
        ]);

        $this->fakeProviderFunctionCall('check_drug_safety', [
            'product_name' => 'Paracetamol',
        ]);

        $response = $this->actingAs($this->activeUser())->postJson('/api/chatbot/message', [
            'message' => 'đau đầu nên dùng gì',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('response_type', 'drug_safety_check')
            ->assertJsonPath('products.0.name', 'Paracetamol 500mg');

        $this->assertStringContainsString('Paracetamol 500mg', (string) $response->json('response'));
    }
}
