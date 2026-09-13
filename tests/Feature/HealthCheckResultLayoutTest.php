<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\TriageSession;
use App\Models\User;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The result page's shape, not its content: the visitor answered five questions and
 * the page used to make them scroll past an AI essay to find out how the conclusion
 * was reached. These tests pin the restructure — a journey rail for the answers, a
 * tab hub for the prose — and the two rules that must survive it: an emergency page
 * hides nothing behind a control, and a browser without JavaScript still reads the
 * whole page.
 */
class HealthCheckResultLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Category::create(['name' => 'Thuốc giảm đau - Hạ sốt', 'description' => 'Giảm đau, hạ sốt']);
        $this->seed(MedicalKnowledgeSeeder::class);

        $this->user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
            'birth_date' => now()->subYears(34)->toDateString(),
        ]);
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
            'safety_net' => 'Đi khám nếu sốt cao.',
            'collected_data' => [
                'raw_symptom_text' => 'Đau đầu',
                'duration_days' => 1,
                'medications_taken' => 'Chưa dùng gì',
                'history' => 'Không có',
                'other_symptoms' => 'Không có thêm',
            ],
        ], $attributes));
    }

    public function test_the_journey_rail_shows_every_answer_the_visitor_gave(): void
    {
        $session = $this->completedSession();

        $response = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk();

        $response->assertSee('health-journey', false);
        $response->assertSee(__('messages.health.journey_title'));
        $response->assertSee('Đau đầu');
        $response->assertSee('Chưa dùng gì');
        $response->assertSee(__('messages.health.outcome_self_care'));
    }

    public function test_the_rail_names_each_of_the_five_steps(): void
    {
        $session = $this->completedSession();

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        foreach (['step_1', 'step_2', 'step_3', 'step_4', 'step_5'] as $key) {
            $this->assertStringContainsString(__("messages.health.{$key}"), $content);
        }
    }

    public function test_the_rail_has_a_horizontal_and_a_stacked_form(): void
    {
        $session = $this->completedSession();

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        // Horizontal rail on wide screens, stacked on phones: five answers do not
        // fit across a 375px column as a row.
        $this->assertMatchesRegularExpression(
            '/\.health-journey\s*\{[^}]*display:\s*flex/s',
            $content
        );
        $this->assertMatchesRegularExpression(
            '/@media\s*\(max-width:[^)]*\)\s*\{[^@]*\.health-journey\s*\{[^}]*flex-direction:\s*column/s',
            $content
        );

        // The connector lives on the item, not on a wrapper, so the layout swap
        // needs no second rule set.
        $this->assertStringContainsString('.health-journey__dot', $content);
    }

    public function test_the_page_groups_its_sections_into_tabs(): void
    {
        $session = $this->completedSession();

        $response = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk();

        $response->assertSee('role="tablist"', false);
        $response->assertSee('tab-pane', false);
        $response->assertSee('hub-briefing', false);
        $response->assertSee('hub-audit', false);

        // The audit trail is still on the page, just inside its own panel.
        $response->assertSee(__('messages.health.basis_title'));
    }

    public function test_an_emergency_page_never_hides_content_behind_a_tab(): void
    {
        $session = $this->completedSession([
            'outcome' => 'emergency',
            'advice_given' => null,
            'safety_net' => null,
            'collected_data' => ['raw_symptom_text' => 'Đau ngực dữ dội'],
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk();

        // A red-flag page shows everything at once: the 115 action, the red flags
        // and the audit trail all in view with no control between them.
        $response->assertSee('tel:115', false);
        $response->assertSee(__('messages.health.outcome_emergency'));
        $response->assertDontSee('role="tablist"', false);
    }

    public function test_the_products_tab_only_exists_where_products_can(): void
    {
        $selfCare = $this->completedSession();

        $this->actingAs($this->user)
            ->get(route('health.result', $selfCare->MaSL))
            ->assertOk()
            ->assertSee('hub-products', false);

        // A referral says outright that no medicine matches, so offering a products
        // tab would contradict the conclusion directly above it.
        $referral = $this->completedSession([
            'outcome' => 'refer_doctor',
            'collected_data' => ['raw_symptom_text' => 'sốt cao 3 ngày'],
        ]);

        $this->actingAs($this->user)
            ->get(route('health.result', $referral->MaSL))
            ->assertOk()
            ->assertDontSee('hub-products', false);
    }

    public function test_a_browser_without_javascript_sees_every_panel(): void
    {
        $session = $this->completedSession();

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        // Bootstrap hides a `.tab-pane` without `.show active`, so a visitor whose
        // script never runs would see one panel and a tab bar that does nothing.
        // The reading path cannot depend on a click.
        $this->assertMatchesRegularExpression(
            '/html:not\(\.js\)[^{]*\.health-hub__panels[^{]*\{[^}]*display:\s*block/s',
            $content
        );
    }

    public function test_the_print_block_shows_every_panel_and_drops_the_tab_bar(): void
    {
        $session = $this->completedSession();

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        $printBlock = substr($content, (int) strrpos($content, '@media print'));
        $this->assertStringContainsString('.health-hub__tabbar { display: none !important; }', $printBlock);
        $this->assertMatchesRegularExpression(
            '/\.health-hub__panels\s+\.tab-pane[^{]*\{[^}]*display:\s*block\s*!important/s',
            $printBlock
        );
    }
}
