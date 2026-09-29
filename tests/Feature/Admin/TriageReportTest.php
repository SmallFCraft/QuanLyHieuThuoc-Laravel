<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\TriageSession;
use App\Models\User;
use App\Models\UserHealthProfile;
use App\Services\Triage\TriageReportService;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TriageReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $customer;
    protected User $pharmacist;

    protected function setUp(): void
    {
        parent::setUp();

        // The knowledge seeder resolves catalog categories by name, so it must exist first.
        Category::create([
            'name' => 'Thuốc giảm đau - Hạ sốt',
            'description' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(MedicalKnowledgeSeeder::class);

        $this->admin = User::factory()->create(['role' => 'Administrator', 'status' => 'active']);
        $this->customer = User::factory()->create(['role' => 'User', 'status' => 'active']);
        $this->pharmacist = User::factory()->create(['role' => 'Pharmacist', 'status' => 'active']);
    }

    private function makeSession(array $attributes = []): TriageSession    {
        return TriageSession::create(array_merge([
            'MaKH' => $this->customer->MaKH,
            'collected_data' => [
                'raw_symptom_text' => 'đau đầu hai ngày',
                'duration_days' => 2,
                'medications_taken' => 'chưa dùng gì',
                'skipped' => [],
            ],
            'matched_symptoms' => ['headache'],
            'triggered_red_flags' => [],
            'outcome' => 'self_care',
            'possible_condition_groups' => ['đau đầu căng cơ'],
            'advice_given' => 'Nghỉ ngơi và uống đủ nước.',
            'safety_net' => 'Đi khám nếu đau đầu dữ dội.',
            'current_step' => 5,
            'entry_point' => 'page',
            'status' => 'completed',
        ], $attributes));
    }

    private function seedAllOutcomes(): void
    {
        $this->makeSession(['outcome' => 'self_care']);
        $this->makeSession(['outcome' => 'pharmacist_review', 'status' => 'pending_pharmacist']);
        $this->makeSession(['outcome' => 'refer_doctor']);
        $this->makeSession([
            'outcome' => 'emergency',
            'triggered_red_flags' => [
                ['code' => 'stiff_neck_fever', 'name_vi' => 'sốt kèm cứng cổ', 'severity' => 'emergency'],
            ],
        ]);
    }

    // --- Access control ---

    public function test_administrator_can_open_the_report(): void
    {
        $this->makeSession();

        $this->actingAs($this->admin)
            ->get(route('admin.triageReports.index'))
            ->assertOk()
            ->assertSee('Báo cáo sàng lọc y tế');
    }

    public function test_customer_is_forbidden(): void
    {
        $this->actingAs($this->customer)
            ->get(route('admin.triageReports.index'))
            ->assertForbidden();
    }

    public function test_pharmacist_is_forbidden(): void
    {
        // Pharmacists work their own review queue; the aggregate report is admin-only.
        $this->actingAs($this->pharmacist)
            ->get(route('admin.triageReports.index'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.triageReports.index'))->assertRedirect(route('login'));
    }

    // --- Filtering ---

    public function test_outcome_filter_returns_only_matching_sessions(): void
    {
        $selfCare = $this->makeSession(['outcome' => 'self_care']);
        $emergency = $this->makeSession(['outcome' => 'emergency']);

        $this->actingAs($this->admin)
            ->get(route('admin.triageReports.index', ['outcome' => 'emergency']))
            ->assertOk()
            ->assertSee('SL-' . $emergency->MaSL)
            ->assertDontSee('SL-' . $selfCare->MaSL);
    }

    public function test_date_range_filter_narrows_results(): void
    {
        $old = $this->makeSession();
        $old->forceFill(['created_at' => now()->subDays(20)])->save();

        $recent = $this->makeSession();

        $this->actingAs($this->admin)
            ->get(route('admin.triageReports.index', [
                'date_from' => now()->subDays(3)->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('SL-' . $recent->MaSL)
            ->assertDontSee('SL-' . $old->MaSL);
    }

    // --- Service metrics ---

    public function test_summary_counts_every_outcome(): void
    {
        $this->seedAllOutcomes();

        $summary = app(TriageReportService::class)->getSummary();

        $this->assertSame(4, $summary['total']);
        $this->assertSame(1, $summary['outcomes']['self_care']);
        $this->assertSame(1, $summary['outcomes']['pharmacist_review']);
        $this->assertSame(1, $summary['outcomes']['refer_doctor']);
        $this->assertSame(1, $summary['outcomes']['emergency']);
        $this->assertSame(50.0, $summary['referral_rate']);
        $this->assertSame(1, $summary['pending_pharmacist']);
    }

    public function test_average_wait_uses_reviewed_sessions_only(): void
    {
        $reviewed = $this->makeSession(['status' => 'reviewed', 'pharmacist_id' => $this->pharmacist->MaKH]);
        $reviewed->forceFill([
            'created_at' => now()->subMinutes(30),
            'reviewed_at' => now(),
        ])->save();

        $this->makeSession(['status' => 'completed']);

        $summary = app(TriageReportService::class)->getSummary();

        $this->assertSame(1, $summary['reviewed_count']);
        $this->assertEqualsWithDelta(30.0, $summary['average_wait_minutes'], 1.0);
    }

    public function test_daily_trend_fills_empty_days_with_zero(): void
    {
        $this->makeSession();

        $trend = app(TriageReportService::class)->getDailyTrend(7);

        $this->assertCount(7, $trend['labels']);
        $this->assertCount(7, $trend['data']);
        $this->assertSame(1, $trend['data'][6]);
        $this->assertSame([0, 0, 0, 0, 0, 0], array_slice($trend['data'], 0, 6));
    }

    public function test_top_symptoms_translates_codes_to_vietnamese(): void
    {
        $this->makeSession(['matched_symptoms' => ['headache']]);
        $this->makeSession(['matched_symptoms' => ['headache', 'fever']]);

        $top = app(TriageReportService::class)->getTopSymptoms();

        $this->assertSame('headache', $top[0]['code']);
        $this->assertSame('đau đầu', $top[0]['name_vi']);
        $this->assertSame(2, $top[0]['count']);
    }

    public function test_entry_point_split_counts_both_sources(): void
    {
        $this->makeSession(['entry_point' => 'chat']);
        $this->makeSession(['entry_point' => 'page']);
        $this->makeSession(['entry_point' => 'page']);

        $split = app(TriageReportService::class)->getEntryPointSplit();

        $this->assertSame(1, $split['chat']);
        $this->assertSame(2, $split['page']);
    }

    // --- Charts & export ---

    public function test_outcome_chart_endpoint_returns_expected_structure(): void
    {
        $this->seedAllOutcomes();

        $this->actingAs($this->admin)
            ->getJson(route('admin.triageReports.outcomeChart'))
            ->assertOk()
            ->assertJsonStructure([
                'labels',
                'data',
                'colors',
                'rows' => [['outcome', 'label', 'count', 'color', 'icon']],
            ]);
    }

    public function test_trend_chart_endpoint_clamps_days(): void
    {
        $this->makeSession();

        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.triageReports.trendChart', ['days' => 5000]))
            ->assertOk()
            ->assertJsonStructure(['labels', 'data', 'days']);

        $this->assertSame(365, $response->json('days'));
        $this->assertCount(365, $response->json('data'));
    }

    public function test_export_returns_an_excel_download(): void
    {
        $this->makeSession();

        $response = $this->actingAs($this->admin)->get(route('admin.triageReports.export'));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('content-type')
        );
    }

    // --- Privacy ---

    public function test_detail_page_never_exposes_the_health_profile(): void
    {
        UserHealthProfile::create([
            'MaKH' => $this->customer->MaKH,
            'allergies' => ['Paracetamol'],
            'consented_at' => now(),
        ]);

        $session = $this->makeSession();

        $this->actingAs($this->admin)
            ->get(route('admin.triageReports.show', $session->MaSL))
            ->assertOk()
            ->assertSee('SL-' . $session->MaSL)
            ->assertDontSee('Paracetamol');
    }

    public function test_detail_page_shows_matched_symptoms_and_pharmacist_note(): void
    {
        $session = $this->makeSession([
            'status' => 'reviewed',
            'pharmacist_id' => $this->pharmacist->MaKH,
            'pharmacist_note' => 'Đã tư vấn qua điện thoại.',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.triageReports.show', $session->MaSL))
            ->assertOk()
            ->assertSee('đau đầu')
            ->assertSee('Đã tư vấn qua điện thoại.');
    }

    public function test_empty_state_is_rendered_when_no_sessions_match(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.triageReports.index'))
            ->assertOk()
            ->assertSee('Chưa có ca sàng lọc nào phù hợp bộ lọc hiện tại.');
    }
}
