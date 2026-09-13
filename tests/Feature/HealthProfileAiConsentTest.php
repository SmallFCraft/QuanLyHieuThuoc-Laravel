<?php

namespace Tests\Feature;

use App\Http\Controllers\HealthProfileController;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserHealthProfile;
use Carbon\CarbonImmutable;
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

    public function test_unticking_ai_consent_revokes_it_and_audits_the_transition(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.health.update'), $this->payload(['ai_consent' => '1']));
        $this->assertTrue(UserHealthProfile::where('MaKH', $user->MaKH)->firstOrFail()->hasAiConsent());

        $this->actingAs($user)->put(route('profile.health.update'), $this->payload());

        $profile = UserHealthProfile::where('MaKH', $user->MaKH)->firstOrFail();
        $log = AuditLog::where('action', 'health_profile_updated')->latest('MaNK')->firstOrFail();
        $serialized = json_encode($log->getAttributes(), JSON_UNESCAPED_UNICODE);

        $this->assertNull($profile->ai_consent_at);
        $this->assertFalse($profile->hasAiConsent());
        // Only the consent transition changed, and it is named by field, not by value.
        $this->assertSame(['ai_consent_at'], $log->new_values['changed_fields']);
        $this->assertStringNotContainsString('Penicillin', $serialized);
    }

    public function test_ai_consent_changed_fields_track_the_transition_not_the_timestamp(): void
    {
        // The HTTP path hands changedFields() the very same Carbon instance it read off
        // the model, so a string comparison can never differ there. The rule this guards
        // is the comparison itself: consent is a state (null vs set), not a value.
        $controller = new HealthProfileController();
        $changedFields = new \ReflectionMethod($controller, 'changedFields');

        $granted = (new UserHealthProfile())->forceFill(['ai_consent_at' => now()->subDay()]);
        $revoked = new UserHealthProfile();

        // Two distinct, non-null timestamps: same state, so nothing changed.
        $this->assertSame([], $changedFields->invoke($controller, $granted, ['ai_consent_at' => now()]));

        // Granting and revoking are real transitions and must be named.
        $this->assertSame(['ai_consent_at'], $changedFields->invoke($controller, $granted, ['ai_consent_at' => null]));
        $this->assertSame(['ai_consent_at'], $changedFields->invoke($controller, $revoked, ['ai_consent_at' => now()]));
    }

    public function test_sibling_form_error_does_not_untick_the_consent_boxes(): void
    {
        // The profile page hosts profile-info, password, avatar and health forms. A
        // validation error in any of them flashes _old_input, but only the health form
        // may influence the health checkboxes — otherwise a mistyped phone number
        // silently revokes medical consent on the next save.
        $user = User::factory()->create();
        UserHealthProfile::create([
            'MaKH' => $user->MaKH,
            'consented_at' => now(),
            'ai_consent_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => 'not-a-phone',
            ])
            ->assertSessionHasErrors('phone');

        $html = $this->actingAs($user)->get(route('profile.edit'))->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]+id="health_consent"[^>]+checked/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]+id="health_ai_consent"[^>]+checked/', $html);
    }

    public function test_validation_redirect_keeps_both_consent_checkboxes_unticked(): void
    {
        $user = User::factory()->create();
        UserHealthProfile::create([
            'MaKH' => $user->MaKH,
            'consented_at' => now(),
            'ai_consent_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->from(route('profile.edit'))
            ->put(route('profile.health.update'), ['weight_kg' => '501']);

        $response->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('weight_kg');

        $html = $this->actingAs($user)->get(route('profile.edit'))->getContent();

        $this->assertDoesNotMatchRegularExpression('/<input[^>]+id="health_consent"[^>]+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]+id="health_ai_consent"[^>]+checked/', $html);
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
