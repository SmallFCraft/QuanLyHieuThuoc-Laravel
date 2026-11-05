<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\TriageSession;
use App\Models\User;
use App\Services\Triage\Ai\TriageAiBriefingService;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The numeric analysis block on the result page.
 *
 * The metrics themselves are computed by the app from persisted columns — the
 * model never invents a number. What the model may add is `data_analysis`: a
 * short prose reading of the metrics, validated so every digit it writes appears
 * in the metric table. The chart and table must render with no AI at all.
 */
class TriageBriefingAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Category::create(['name' => 'Thuốc giảm đau - Hạ sốt', 'description' => 'Giảm đau, hạ sốt']);
        $this->seed(MedicalKnowledgeSeeder::class);

        config()->set('chatbot.ai.triage.briefing', true);

        $this->user = User::factory()->create(['role' => 'User', 'status' => 'active']);
    }

    protected function fakeToolCall(array $arguments): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'ghi_dien_giai',
                            'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE),
                        ],
                    ]],
                ],
            ]],
        ])]);
    }

    protected function triageSession(array $attributes = []): TriageSession
    {
        return TriageSession::create(array_merge([
            'MaKH' => $this->user->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'self_care',
            'advice_given' => 'Nghỉ ngơi, uống đủ nước.',
            'safety_net' => 'Đi khám nếu nặng hơn.',
            'matched_symptoms' => ['sore_throat'],
            'triggered_red_flags' => [],
            'collected_data' => ['raw_symptom_text' => 'dau hong', 'duration_days' => 1],
        ], $attributes));
    }

    protected function fullBriefing(array $overrides = []): array
    {
        return array_merge([
            'why' => 'Em kết luận tự chăm sóc vì anh/chị đau họng nhẹ, mới trong hôm nay.',
            'possible_causes' => ['Viêm họng do virus.'],
            'self_care' => ['Súc miệng nước muối ấm.'],
            'watch_for' => ['Sốt cao hoặc khó nuốt.'],
            'see_doctor_when' => ['Đau họng quá 3 ngày.'],
            'ask_pharmacist' => ['Nên dùng thuốc súc họng nào?'],
        ], $overrides);
    }

    public function test_the_metrics_block_renders_without_any_ai_briefing(): void
    {
        $session = $this->triageSession();

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        // The analysis lives in the basis tab ("Căn cứ kết luận"), where the
        // conclusion's justification belongs.
        $audit = substr($content, (int) strpos($content, 'id="hub-audit"'));
        $this->assertStringContainsString(__('messages.health.analysis_title'), $audit);
        $this->assertStringContainsString(
            __('messages.health.analysis_score_level', ['level' => __('messages.health.outcome_self_care')]),
            $audit
        );
        $this->assertStringContainsString('health-gauge', $audit);
    }

    /** The score is banded: a self-care case can never read above 24. */
    public function test_the_score_stays_inside_the_outcome_band(): void
    {
        $flagged = $this->triageSession([
            'outcome' => 'self_care',
            'triggered_red_flags' => [
                ['code' => 'high_fever', 'name_vi' => 'Sốt cao', 'severity' => 'caution'],
            ],
            'matched_symptoms' => ['fever', 'headache', 'cough'],
            'collected_data' => ['raw_symptom_text' => 'sot', 'duration_days' => 7],
        ]);

        $this->actingAs($this->user)
            ->get(route('health.result', $flagged->MaSL))
            ->assertOk();

        // self_care band is 0-24; even with 3 caution flags (9) + 6 duration days
        // (24) + 2 extra symptoms (10), the score is capped.
        $rank = array_search('self_care', array_reverse(\App\Services\Triage\TriageReportService::OUTCOMES), true);
        $this->assertSame(0, $rank);
    }

    /** An emergency session anchors at 75, the band bottom, whatever its factors. */
    public function test_an_emergency_score_starts_at_its_band_base(): void
    {
        $emergency = $this->triageSession([
            'outcome' => 'emergency',
            'advice_given' => 'Gọi 115 ngay.',
            'safety_net' => null,
            'triggered_red_flags' => [
                ['code' => 'chest_pain', 'name_vi' => 'Đau ngực', 'severity' => 'emergency'],
            ],
        ]);

        $this->actingAs($this->user)
            ->get(route('health.result', $emergency->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.analysis_score_level', ['level' => __('messages.health.outcome_emergency')]), false);
    }

    public function test_a_valid_data_analysis_is_stored_and_rendered(): void
    {
        $this->fakeToolCall($this->fullBriefing([
            'data_analysis' => [
                'Ca của anh/chị có 1 triệu chứng chính, kéo dài 1 ngày.',
                'Không có cờ đỏ nào, nên mức nguy cơ ở thang thấp nhất.',
            ],
        ]));

        $session = $this->triageSession();
        app(TriageAiBriefingService::class)->generate($session);
        $session = $session->refresh();

        $this->assertSame('ok', $session->ai_briefing_status);
        $this->assertCount(2, $session->ai_briefing['data_analysis']);

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        // The AI's reading renders inside the analysis block, in the basis tab.
        $audit = substr($content, (int) strpos($content, 'id="briefing-analysis"'));
        $this->assertStringContainsString('Ca của anh/chị có 1 triệu chứng chính', $audit);
    }

    public function test_a_data_analysis_number_outside_the_metrics_is_dropped(): void
    {
        $this->fakeToolCall($this->fullBriefing([
            'data_analysis' => [
                'Ca của anh/chị có 1 triệu chứng, kéo dài 1 ngày.',
                'Khoảng 82 trường hợp tương tự tự chăm sóc được.',
            ],
        ]));

        $session = $this->triageSession();
        app(TriageAiBriefingService::class)->generate($session);
        $session = $session->refresh();

        // 82 never appears in the metric table, so that line cannot be trusted and is
        // dropped; the metric-backed line survives.
        $this->assertCount(1, $session->ai_briefing['data_analysis']);
        $this->assertStringContainsString('1 triệu chứng', $session->ai_briefing['data_analysis'][0]);
    }

    public function test_a_briefing_without_data_analysis_is_still_accepted(): void
    {
        $this->fakeToolCall($this->fullBriefing());

        $session = $this->triageSession();
        app(TriageAiBriefingService::class)->generate($session);
        $session = $session->refresh();

        $this->assertSame('ok', $session->ai_briefing_status);
        $this->assertArrayNotHasKey('data_analysis', $session->ai_briefing);
    }

    public function test_the_briefing_pane_carries_the_same_metrics_block(): void
    {
        $this->fakeToolCall($this->fullBriefing());

        $session = $this->triageSession();
        app(TriageAiBriefingService::class)->generate($session);
        $session = $session->refresh();

        $this->actingAs($this->user)
            ->get(route('health.result.briefing-pane', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.analysis_title'), false);
    }
}
