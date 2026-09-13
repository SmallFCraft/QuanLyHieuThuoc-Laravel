<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserHealthProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthProfileAiConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_profile_tracks_ai_consent_correctly(): void
    {
        $user = User::factory()->create();
        $profile = UserHealthProfile::create([
            'MaKH' => $user->MaKH,
            'consented_at' => now(),
            'ai_consent_at' => null,
        ]);

        $this->assertFalse($profile->hasAiConsent());

        $profile->update(['ai_consent_at' => now()]);
        $profile->refresh();

        $this->assertTrue($profile->hasAiConsent());
        $this->assertNotNull($profile->ai_consent_at);
    }
}
