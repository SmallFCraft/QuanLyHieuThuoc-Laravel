<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\TriageSession;
use App\Models\User;
use App\Models\UserHealthProfile;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TriageAiDraftContextTest extends TestCase
{
    use RefreshDatabase;

    protected User $pharmacist;

    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config()->set('chatbot.ai.triage.pharmacist_draft', true);

        Category::create([
            'name' => 'Thuốc giảm đau - Hạ sốt',
            'description' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(MedicalKnowledgeSeeder::class);

        $this->pharmacist = User::factory()->create([
            'role' => 'Pharmacist',
            'status' => 'active',
        ]);

        $this->customer = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
            'birth_date' => now()->subYears(41)->toDateString(),
        ]);
    }

    protected function fakeProviderReply(string $content = 'Dạ, em xin chào anh/chị.'): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
        ])]);
    }

    public function test_prompt_includes_age_and_prior_session_when_ai_consent_granted(): void
    {
        $this->fakeProviderReply();

        UserHealthProfile::create([
            'MaKH' => $this->customer->MaKH,
            'allergies' => ['aspirin'],
            'chronic_conditions' => ['cao huyết áp'],
            'current_medications' => ['amlodipine'],
            'consented_at' => now(),
            'ai_consent_at' => now(),
        ]);

        TriageSession::create([
            'MaKH' => $this->customer->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'reviewed',
            'outcome' => 'self_care',
            'collected_data' => ['raw_symptom_text' => 'dau hong va ho nhe tuan truoc'],
            'created_at' => now()->subDays(3),
        ]);

        $currentSession = TriageSession::create([
            'MaKH' => $this->customer->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'pending_pharmacist',
            'outcome' => 'pharmacist_review',
            'advice_given' => 'Cần nghỉ ngơi.',
            'safety_net' => 'Đi khám nếu sốt cao.',
            'matched_symptoms' => ['fever'],
            'triggered_red_flags' => [],
            'pharmacist_id' => $this->pharmacist->MaKH,
        ]);

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.draft', $currentSession->MaSL));

        Http::assertSent(function ($request) {
            $userContent = collect($request->data()['messages'] ?? [])
                ->firstWhere('role', 'user')['content'] ?? '';

            $this->assertStringContainsString('41 tuổi', $userContent);
            $this->assertStringContainsString('aspirin', $userContent);
            $this->assertStringContainsString('dau hong va ho nhe tuan truoc', $userContent);
            $this->assertStringContainsString('Kết luận của hệ thống: pharmacist_review', $userContent);

            return true;
        });
    }

    /**
     * AI consent gates the health profile only. Age and the customer's own past
     * triage descriptions are session-derived, not profile data, and the context
     * service already sends them without consent (see TriageAiContextServiceTest).
     * What must never appear is the encrypted, purpose-limited profile.
     */
    public function test_prompt_excludes_profile_when_ai_consent_not_granted(): void
    {
        $this->fakeProviderReply();

        UserHealthProfile::create([
            'MaKH' => $this->customer->MaKH,
            'allergies' => ['amoxicillin'],
            'chronic_conditions' => ['tiểu đường'],
            'current_medications' => ['metformin'],
            'consented_at' => now(),
            'ai_consent_at' => null,
        ]);

        TriageSession::create([
            'MaKH' => $this->customer->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'reviewed',
            'outcome' => 'self_care',
            'collected_data' => ['raw_symptom_text' => 'ngua co tay sau khi an hai san'],
            'created_at' => now()->subDays(2),
        ]);

        $currentSession = TriageSession::create([
            'MaKH' => $this->customer->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'pending_pharmacist',
            'outcome' => 'pharmacist_review',
            'advice_given' => 'Uống nhiều nước.',
            'safety_net' => 'Khám nếu đau ngực.',
            'matched_symptoms' => ['cough'],
            'triggered_red_flags' => [],
            'pharmacist_id' => $this->pharmacist->MaKH,
        ]);

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.draft', $currentSession->MaSL));

        Http::assertSent(function ($request) {
            $userContent = collect($request->data()['messages'] ?? [])
                ->firstWhere('role', 'user')['content'] ?? '';

            $this->assertStringNotContainsString('amoxicillin', $userContent);
            $this->assertStringNotContainsString('tiểu đường', $userContent);
            $this->assertStringNotContainsString('metformin', $userContent);
            $this->assertStringNotContainsString('Tiền sử y tế đã xác nhận', $userContent);
            // Session-derived context still flows: proves the block is wired at all,
            // so the assertions above are not passing just because nothing was added.
            $this->assertStringContainsString('41 tuổi', $userContent);
            $this->assertStringContainsString('ngua co tay sau khi an hai san', $userContent);
            $this->assertStringContainsString('Kết luận của hệ thống: pharmacist_review', $userContent);

            return true;
        });
    }

    public function test_system_outcome_always_present_as_regression_guard(): void
    {
        $this->fakeProviderReply();

        $session = TriageSession::create([
            'MaKH' => $this->customer->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'pending_pharmacist',
            'outcome' => 'pharmacist_review',
            'advice_given' => 'Theo dõi nhiệt độ.',
            'safety_net' => 'Đi cấp cứu nếu co giật.',
            'matched_symptoms' => ['fever'],
            'triggered_red_flags' => [],
            'pharmacist_id' => $this->pharmacist->MaKH,
        ]);

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.draft', $session->MaSL));

        Http::assertSent(function ($request) {
            $userContent = collect($request->data()['messages'] ?? [])
                ->firstWhere('role', 'user')['content'] ?? '';

            $this->assertStringContainsString('Kết luận của hệ thống: pharmacist_review', $userContent);
            $this->assertStringContainsString('Đi cấp cứu nếu co giật.', $userContent);

            return true;
        });
    }
}
