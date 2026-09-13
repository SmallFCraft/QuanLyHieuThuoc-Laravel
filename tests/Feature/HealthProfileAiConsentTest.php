<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserHealthProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthProfileAiConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'allergies' => ['Penicillin'],
            'chronic_conditions' => [],
            'current_medications' => [],
            'is_pregnant' => '0',
            'is_breastfeeding' => '0',
            'consent' => '1',
        ], $overrides);
    }

    public function test_ai_consent_checkbox_grants_ai_consent(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('profile.health.update'), $this->payload(['ai_consent' => '1']))
            ->assertSessionHasNoErrors();

        $profile = UserHealthProfile::where('MaKH', $user->MaKH)->firstOrFail();

        $this->assertNotNull($profile->ai_consent_at);
        $this->assertTrue($profile->hasAiConsent());
    }

    public function test_ai_consent_is_granted_without_safety_consent(): void
    {
        // The two consents are independent: declining pharmacist warnings must not
        // silently decline - or grant - the AI one.
        $user = User::factory()->create();
        $payload = $this->payload(['ai_consent' => '1']);
        unset($payload['consent']);

        $this->actingAs($user)->put(route('profile.health.update'), $payload);

        $profile = UserHealthProfile::where('MaKH', $user->MaKH)->firstOrFail();

        $this->assertFalse($profile->hasConsented());
        $this->assertTrue($profile->hasAiConsent());
    }

    public function test_unticking_ai_consent_revokes_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.health.update'), $this->payload(['ai_consent' => '1']));
        $this->assertTrue(UserHealthProfile::where('MaKH', $user->MaKH)->firstOrFail()->hasAiConsent());

        $this->actingAs($user)->put(route('profile.health.update'), $this->payload());

        $profile = UserHealthProfile::where('MaKH', $user->MaKH)->firstOrFail();

        $this->assertNull($profile->ai_consent_at);
        $this->assertFalse($profile->hasAiConsent());
    }

    public function test_re_granting_ai_consent_keeps_the_original_timestamp(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.health.update'), $this->payload(['ai_consent' => '1']));
        $grantedAt = UserHealthProfile::where('MaKH', $user->MaKH)->firstOrFail()->ai_consent_at;

        $this->travel(5)->minutes();

        $this->actingAs($user)->put(route('profile.health.update'), $this->payload(['ai_consent' => '1']));

        $this->assertTrue($grantedAt->equalTo(
            UserHealthProfile::where('MaKH', $user->MaKH)->firstOrFail()->ai_consent_at
        ));
    }

    public function test_ai_consent_checkbox_labels_are_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('profile.edit'));

        // Colour alone is not the state indicator, and the label wraps the text so
        // the click target and the accessible name both belong to the input.
        $response->assertOk()
            ->assertSee('id="health_ai_consent"', false)
            ->assertSee('for="health_ai_consent"', false)
            ->assertSee(__('messages.health_profile.ai_consent_title'))
            ->assertSee(__('messages.health_profile.ai_consent_hint'));
    }

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
