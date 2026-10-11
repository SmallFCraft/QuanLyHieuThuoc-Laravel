<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\TriageSession;
use App\Models\User;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TriageBriefingPaneTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Category::create(['name' => 'Thuốc giảm đau - Hạ sốt', 'description' => 'Giảm đau, hạ sốt']);
        $this->seed(MedicalKnowledgeSeeder::class);

        $this->user = User::factory()->create(['role' => 'User', 'status' => 'active']);
    }

    protected function completedSession(array $attributes = []): TriageSession
    {
        return TriageSession::create(array_merge([
            'MaKH' => $this->user->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'self_care',
            'advice_given' => 'Nghỉ ngơi, uống đủ nước.',
            'collected_data' => ['raw_symptom_text' => 'dau dau nhe', 'skipped' => []],
        ], $attributes));
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $session = $this->completedSession();

        $this->get(route('health.result.briefing-pane', $session->MaSL))
            ->assertRedirect('/login');
    }

    public function test_another_customers_session_is_not_found(): void
    {
        $other = User::factory()->create(['role' => 'User', 'status' => 'active']);
        $session = $this->completedSession(['MaKH' => $other->MaKH]);

        $this->actingAs($this->user)
            ->get(route('health.result.briefing-pane', $session->MaSL))
            ->assertNotFound();
    }

    public function test_an_emergency_session_has_no_briefing_pane(): void
    {
        $session = $this->completedSession(['outcome' => 'emergency', 'advice_given' => null]);

        $this->actingAs($this->user)
            ->get(route('health.result.briefing-pane', $session->MaSL))
            ->assertNotFound();
    }

    public function test_an_in_progress_session_has_no_briefing_pane(): void
    {
        $session = $this->completedSession(['status' => 'in_progress', 'advice_given' => null]);

        $this->actingAs($this->user)
            ->get(route('health.result.briefing-pane', $session->MaSL))
            ->assertNotFound();
    }

    public function test_a_pending_briefing_returns_202_with_the_skeleton(): void
    {
        $session = $this->completedSession(['ai_briefing' => null, 'ai_briefing_status' => null]);

        $this->actingAs($this->user)
            ->get(route('health.result.briefing-pane', $session->MaSL))
            ->assertStatus(202)
            ->assertSee('health-skeleton', false);
    }

    public function test_a_finished_briefing_returns_200_with_its_sections(): void
    {
        $session = $this->completedSession([
            'ai_briefing' => [
                'why' => 'Đau đầu nhẹ thường gặp khi thiếu ngủ.',
                'self_care' => ['Nghỉ ngơi trong phòng yên tĩnh.'],
                'products' => [],
            ],
            'ai_briefing_status' => 'ok',
        ]);

        $this->actingAs($this->user)
            ->get(route('health.result.briefing-pane', $session->MaSL))
            ->assertOk()
            ->assertSee('Đau đầu nhẹ thường gặp khi thiếu ngủ.', false)
            ->assertSee('briefing-self-care', false)
            ->assertSee('<template id="briefing-care-products">', false);
    }

    public function test_a_failed_briefing_returns_200_with_the_unavailable_notice(): void
    {
        $session = $this->completedSession(['ai_briefing' => null, 'ai_briefing_status' => 'failed']);

        $this->actingAs($this->user)
            ->get(route('health.result.briefing-pane', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.briefing_unavailable'), false);
    }

    public function test_a_finished_briefing_embeds_ai_selected_products_in_the_care_template(): void
    {
        $category = Category::first();
        $product = Product::create([
            'MaDM' => $category->MaDM,
            'name' => 'Thuốc ho Prospan 100ml',
            'ingredients' => 'Cao lá thường xuân',
            'usage' => 'Uống 5ml mỗi ngày 3 lần',
            'price' => 120000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 15,
            'reorder_point' => 2,
            'batch_number' => 'B-PROSPAN',
        ]);

        $session = $this->completedSession([
            'suggested_products' => [],
            'ai_briefing' => [
                'why' => 'Ho có đờm cần thuốc long đờm thảo dược.',
                'self_care' => ['Uống nhiều nước ấm.'],
                'products' => [[
                    'id' => $product->MaSP,
                    'name' => 'Thuốc ho Prospan 100ml',
                    'price' => 120000,
                    'stock_quantity' => 15,
                    'reason' => 'Giúp giảm ho dịu họng an toàn.',
                ]],
            ],
            'ai_briefing_status' => 'ok',
        ]);

        $content = $this->actingAs($this->user)
            ->get(route('health.result.briefing-pane', $session->MaSL))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<template id="briefing-care-products">', $content);
        $template = substr($content, (int) strpos($content, '<template id="briefing-care-products">'));
        $this->assertStringContainsString('Thuốc ho Prospan 100ml', $template);
        $this->assertStringContainsString('120.000 ₫', $template);
        $this->assertStringContainsString('Giúp giảm ho dịu họng an toàn.', $template);
    }
}
