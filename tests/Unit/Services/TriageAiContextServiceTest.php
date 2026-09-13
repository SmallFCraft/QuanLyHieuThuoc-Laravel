<?php

namespace Tests\Unit\Services;

use App\Models\AuditLog;
use App\Models\TriageSession;
use App\Models\User;
use App\Models\UserHealthProfile;
use App\Services\TriageAiContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TriageAiContextServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_context_with_age_profile_and_history(): void
    {
        $user = User::factory()->create([
            'birth_date' => now()->subYears(30)->toDateString(),
        ]);

        UserHealthProfile::create([
            'MaKH' => $user->MaKH,
            'consented_at' => now(),
            'ai_consent_at' => now(),
            'allergies' => ['Aspirin'],
            'chronic_conditions' => ['Viêm dạ dày'],
            'current_medications' => ['Omeprazole'],
        ]);

        TriageSession::create([
            'MaKH' => $user->MaKH,
            'status' => 'reviewed',
            'outcome' => 'self_care',
            'collected_data' => ['raw_symptom_text' => 'Đau bụng nhẹ'],
        ]);

        $session = TriageSession::create([
            'MaKH' => $user->MaKH,
            'status' => 'pending_pharmacist',
            'collected_data' => ['raw_symptom_text' => 'Đau đầu'],
        ]);

        $context = app(TriageAiContextService::class)->buildContext($session);

        $this->assertStringContainsString('30 tuổi', $context);
        $this->assertStringContainsString('Aspirin', $context);
        $this->assertStringContainsString('Viêm dạ dày', $context);
        $this->assertStringContainsString('Đau bụng nhẹ', $context);
    }

    public function test_excludes_health_profile_when_ai_consent_is_missing(): void
    {
        $user = User::factory()->create([
            'birth_date' => now()->subYears(45)->toDateString(),
        ]);

        UserHealthProfile::create([
            'MaKH' => $user->MaKH,
            'consented_at' => now(),
            'ai_consent_at' => null,
            'allergies' => ['SecretAllergy'],
        ]);

        $session = TriageSession::create([
            'MaKH' => $user->MaKH,
            'status' => 'pending_pharmacist',
        ]);

        $context = app(TriageAiContextService::class)->buildContext($session);

        $this->assertStringNotContainsString('SecretAllergy', $context);
        $this->assertStringContainsString('45 tuổi', $context);
    }

    public function test_builds_context_without_any_profile(): void
    {
        $user = User::factory()->create([
            'birth_date' => now()->subYears(60)->toDateString(),
        ]);

        TriageSession::create([
            'MaKH' => $user->MaKH,
            'status' => 'reviewed',
            'outcome' => 'refer_doctor',
            'collected_data' => ['raw_symptom_text' => 'Chóng mặt'],
        ]);

        $session = TriageSession::create([
            'MaKH' => $user->MaKH,
            'status' => 'pending_pharmacist',
        ]);

        $context = app(TriageAiContextService::class)->buildContext($session);

        $this->assertStringContainsString('60 tuổi', $context);
        $this->assertStringContainsString('Chóng mặt', $context);
    }

    public function test_audits_only_when_profile_data_was_included(): void
    {
        $consented = User::factory()->create(['birth_date' => now()->subYears(30)->toDateString()]);
        UserHealthProfile::create([
            'MaKH' => $consented->MaKH,
            'consented_at' => now(),
            'ai_consent_at' => now(),
            'allergies' => ['Aspirin'],
        ]);
        $withProfile = TriageSession::create(['MaKH' => $consented->MaKH, 'status' => 'pending_pharmacist']);

        app(TriageAiContextService::class)->buildContext($withProfile);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ai_context_sent',
            'MaKH' => $consented->MaKH,
        ]);
        $this->assertSame(
            ['MaSL' => $withProfile->MaSL, 'MaKH' => $consented->MaKH],
            AuditLog::where('action', 'ai_context_sent')->sole()->new_values
        );

        $allergyOnly = User::factory()->create(['birth_date' => now()->subYears(30)->toDateString()]);
        UserHealthProfile::create([
            'MaKH' => $allergyOnly->MaKH,
            'consented_at' => now(),
            'ai_consent_at' => null,
            'allergies' => ['SecretAllergy'],
        ]);
        $unconsented = TriageSession::create(['MaKH' => $allergyOnly->MaKH, 'status' => 'pending_pharmacist']);

        $noProfile = User::factory()->create(['birth_date' => now()->subYears(30)->toDateString()]);
        $bare = TriageSession::create(['MaKH' => $noProfile->MaKH, 'status' => 'pending_pharmacist']);

        app(TriageAiContextService::class)->buildContext($unconsented);
        app(TriageAiContextService::class)->buildContext($bare);

        $this->assertSame(1, AuditLog::where('action', 'ai_context_sent')->count());
    }

    public function test_returns_empty_string_when_nothing_is_known(): void
    {
        $session = TriageSession::create([
            'MaKH' => User::factory()->create(['birth_date' => null])->MaKH,
            'status' => 'pending_pharmacist',
        ]);

        $this->assertSame('', app(TriageAiContextService::class)->buildContext($session));
    }
}
