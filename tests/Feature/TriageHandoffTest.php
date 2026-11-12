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

class TriageHandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // TriThucYTeSeeder maps contraindications onto existing categories.
        DanhMuc::factory()->create(['name' => 'Thuốc giảm đau - Hạ sốt']);
        $this->seed(\Database\Seeders\TriThucYTeSeeder::class);
    }

    protected function activeUser(): NguoiDung
    {
        return NguoiDung::factory()->create([
            'role' => 'User',
            'status' => 'active',
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

    public function test_symptom_message_returns_triage_handoff_without_products(): void
    {
        $response = $this->actingAs($this->activeUser())->postJson('/api/chatbot/message', [
            'message' => 'tôi bị đau đầu 2 ngày',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('response_type', 'triage_handoff')
            ->assertJsonPath('triage_handoff.url', route('health.check'))
            ->assertJsonPath('triage_handoff.label', 'Bắt đầu sàng lọc');

        $this->assertSame([], $response->json('products'));
        $this->assertSame([], $response->json('categories'));
        $this->assertTrue($response->json('show_disclaimer'));
    }

    public function test_urgent_message_is_answered_in_widget_without_handoff(): void
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

    public function test_explicit_product_question_falls_through_to_catalog(): void
    {
        SanPham::factory()->create([
            'MaDM' => DanhMuc::where('name', 'Thuốc giảm đau - Hạ sốt')->value('MaDM'),
            'name' => 'Paracetamol 500mg',
            'ingredients' => 'Paracetamol 500mg',
            'usage' => 'Giảm đau, hạ sốt',
            'stock_quantity' => 100,
            'expiration_date' => now()->addYear(),
        ]);

        $response = $this->actingAs($this->activeUser())->postJson('/api/chatbot/message', [
            'message' => 'có thuốc paracetamol không',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertNull($response->json('triage_handoff'));
        $this->assertNotEmpty($response->json('products'));
        $this->assertSame('Paracetamol 500mg', $response->json('products.0.name'));
    }

    public function test_greeting_does_not_return_triage_handoff(): void
    {
        $this->fakeProviderReply('Dạ, em chào anh/chị ạ.');

        $response = $this->actingAs($this->activeUser())->postJson('/api/chatbot/message', [
            'message' => 'xin chào',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertNull($response->json('triage_handoff'));
        $this->assertSame([], $response->json('products'));
    }

    public function test_guest_cannot_use_chatbot(): void
    {
        $this->postJson('/api/chatbot/message', ['message' => 'tôi bị đau đầu 2 ngày'])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('require_login', true);
    }

    public function test_triage_handoff_is_persisted_in_chat_message_metadata(): void
    {
        $nguoiDung = $this->activeUser();

        $this->actingAs($nguoiDung)
            ->postJson('/api/chatbot/message', ['message' => 'tôi bị đau đầu 2 ngày'])
            ->assertOk();

        $assistantMessage = TinNhanChat::where('MaKH', $nguoiDung->MaKH)
            ->where('role', 'assistant')
            ->latest('MaTN')
            ->firstOrFail();

        $this->assertSame('triage_handoff', $assistantMessage->metadata['response_type'] ?? null);
        $this->assertSame(
            route('health.check'),
            $assistantMessage->metadata['triage_handoff']['url'] ?? null
        );
    }

    public function test_function_schema_exposes_four_tools(): void
    {
        $method = new \ReflectionMethod(ChatbotService::class, 'getFunctionSchema');
        $method->setAccessible(true);

        $names = array_column($method->invoke(app(ChatbotService::class)), 'name');

        $this->assertSame(
            ['search_products', 'search_categories', 'assess_symptoms', 'check_drug_safety'],
            $names
        );
    }

    public function test_assess_symptoms_tool_call_returns_handoff_instead_of_running_wizard(): void
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
            ->assertJsonPath('triage_handoff.url', route('health.check'));

        $this->assertSame([], $response->json('products'));
    }

    public function test_check_drug_safety_tool_call_reports_unknown_product(): void
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

    public function test_check_drug_safety_tool_call_checks_a_real_product(): void
    {
        SanPham::factory()->create([
            'MaDM' => DanhMuc::where('name', 'Thuốc giảm đau - Hạ sốt')->value('MaDM'),
            'name' => 'Paracetamol 500mg',
            'ingredients' => 'Paracetamol 500mg',
            'usage' => 'Giảm đau, hạ sốt',
            'stock_quantity' => 100,
            'expiration_date' => now()->addYear(),
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
