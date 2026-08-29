<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TriageSession;
use App\Models\User;
use App\Models\UserHealthProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HealthProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'allergies' => ['Paracetamol', 'Penicillin'],
            'chronic_conditions' => ['loét dạ dày'],
            'current_medications' => ['Amoxicillin 500mg'],
            'is_pregnant' => '0',
            'is_breastfeeding' => '0',
            'weight_kg' => '62.5',
            'notes' => 'Từng bị nổi mề đay khi dùng kháng sinh.',
            'consent' => '1',
        ], $overrides);
    }

    public function test_guest_cannot_update_health_profile(): void
    {
        $response = $this->put(route('profile.health.update'), $this->payload());

        $response->assertRedirect(route('login'));
        $this->assertDatabaseCount('user_health_profiles', 0);
    }

    public function test_user_can_create_health_profile(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put(route('profile.health.update'), $this->payload());

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit') . '#health-profile');

        $profile = UserHealthProfile::where('MaKH', $user->MaKH)->firstOrFail();

        $this->assertSame(['Paracetamol', 'Penicillin'], $profile->allergies);
        $this->assertSame(['loét dạ dày'], $profile->chronic_conditions);
        $this->assertSame(['Amoxicillin 500mg'], $profile->current_medications);
        $this->assertFalse($profile->is_pregnant);
        $this->assertFalse($profile->is_breastfeeding);
        $this->assertSame(62.5, $profile->weight_kg);
        $this->assertSame('Từng bị nổi mề đay khi dùng kháng sinh.', $profile->notes);
    }

    public function test_existing_profile_is_updated_not_duplicated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.health.update'), $this->payload());
        $this->actingAs($user)->put(route('profile.health.update'), $this->payload([
            'allergies' => ['Aspirin'],
        ]));

        $this->assertDatabaseCount('user_health_profiles', 1);
        $this->assertSame(['Aspirin'], UserHealthProfile::where('MaKH', $user->MaKH)->first()->allergies);
    }

    public function test_consent_checkbox_controls_consented_at(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.health.update'), $this->payload());
        $this->assertNotNull(UserHealthProfile::where('MaKH', $user->MaKH)->first()->consented_at);

        $withoutConsent = $this->payload();
        unset($withoutConsent['consent']);

        $this->actingAs($user)->put(route('profile.health.update'), $withoutConsent);
        $this->assertNull(UserHealthProfile::where('MaKH', $user->MaKH)->first()->consented_at);
    }

    public function test_unconsented_profile_is_excluded_by_consented_scope(): void
    {
        $user = User::factory()->create();
        $withoutConsent = $this->payload();
        unset($withoutConsent['consent']);

        $this->actingAs($user)->put(route('profile.health.update'), $withoutConsent);

        $this->assertNull(UserHealthProfile::where('MaKH', $user->MaKH)->consented()->first());
    }

    public function test_health_data_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.health.update'), $this->payload());

        $row = DB::table('user_health_profiles')->where('MaKH', $user->MaKH)->first();

        $this->assertStringNotContainsString('Paracetamol', $row->allergies);
        $this->assertStringNotContainsString('Penicillin', $row->allergies);
        $this->assertStringNotContainsString('dạ dày', $row->chronic_conditions);
        $this->assertStringNotContainsString('Amoxicillin', $row->current_medications);
        $this->assertStringNotContainsString('mề đay', $row->notes);
    }

    public function test_user_cannot_touch_another_users_profile(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->actingAs($userB)->put(route('profile.health.update'), $this->payload([
            'allergies' => ['Ibuprofen'],
        ]));

        $this->actingAs($userA)->put(route('profile.health.update'), $this->payload([
            'allergies' => ['Aspirin'],
        ]));

        $this->assertSame(['Ibuprofen'], UserHealthProfile::where('MaKH', $userB->MaKH)->first()->allergies);
        $this->assertSame(['Aspirin'], UserHealthProfile::where('MaKH', $userA->MaKH)->first()->allergies);

        $this->actingAs($userA)->delete(route('profile.health.destroy'));

        $this->assertDatabaseMissing('user_health_profiles', ['MaKH' => $userA->MaKH]);
        $this->assertDatabaseHas('user_health_profiles', ['MaKH' => $userB->MaKH]);
    }

    public function test_known_terms_merges_all_lists_in_lowercase(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.health.update'), $this->payload([
            'allergies' => ['Paracetamol', 'Paracetamol'],
            'chronic_conditions' => ['Loét Dạ Dày'],
            'current_medications' => ['Amoxicillin'],
        ]));

        $terms = UserHealthProfile::where('MaKH', $user->MaKH)->first()->knownTerms();

        $this->assertSame(['paracetamol', 'loét dạ dày', 'amoxicillin'], $terms);
    }

    public function test_profile_can_be_deleted_and_is_audited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->put(route('profile.health.update'), $this->payload());

        $response = $this->actingAs($user)->delete(route('profile.health.destroy'));

        $response->assertRedirect(route('profile.edit') . '#health-profile');
        $this->assertDatabaseCount('user_health_profiles', 0);
        $this->assertDatabaseHas('audit_logs', [
            'MaKH' => $user->MaKH,
            'action' => 'health_profile_deleted',
        ]);
    }

    public function test_update_is_audited_without_raw_health_content(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.health.update'), $this->payload());

        $log = AuditLog::where('action', 'health_profile_updated')->firstOrFail();
        $serialized = json_encode($log->getAttributes(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Paracetamol', $serialized);
        $this->assertStringNotContainsString('Penicillin', $serialized);
        $this->assertStringNotContainsString('dạ dày', $serialized);
        $this->assertStringNotContainsString('Amoxicillin', $serialized);
        $this->assertStringNotContainsString('mề đay', $serialized);
        $this->assertSame(2, $log->new_values['item_counts']['allergies']);
        $this->assertContains('allergies', $log->new_values['changed_fields']);
    }

    public function test_more_than_thirty_items_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put(route('profile.health.update'), $this->payload([
            'allergies' => array_map(fn (int $i) => "Chat so {$i}", range(1, 31)),
        ]));

        $response->assertSessionHasErrors('allergies');
        $this->assertDatabaseCount('user_health_profiles', 0);
    }

    public function test_item_longer_than_120_characters_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put(route('profile.health.update'), $this->payload([
            'allergies' => [str_repeat('a', 121)],
        ]));

        $response->assertSessionHasErrors('allergies.0');
        $this->assertDatabaseCount('user_health_profiles', 0);
    }

    public function test_blank_items_are_dropped_and_values_trimmed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.health.update'), $this->payload([
            'allergies' => ['  Aspirin  ', '', '   '],
        ]));

        $this->assertSame(['Aspirin'], UserHealthProfile::where('MaKH', $user->MaKH)->first()->allergies);
    }

    public function test_weight_out_of_range_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('profile.health.update'), $this->payload(['weight_kg' => '501']))
            ->assertSessionHasErrors('weight_kg');

        $this->actingAs($user)
            ->put(route('profile.health.update'), $this->payload(['weight_kg' => '0.5']))
            ->assertSessionHasErrors('weight_kg');
    }

    /**
     * A literal "Không" in the allergy field made knownTerms() non-empty, which made the
     * triage skip wizard step 4 entirely — so it never asked about chronic disease,
     * allergy, pregnancy or breastfeeding, and then a follow-up round asked it instead.
     *
     * @return array<string, array{0: string}>
     */
    public static function negationTermProvider(): array
    {
        return [
            'khong' => ['Không'],
            'khong co' => ['không có'],
            'khong co gi' => ['Không có gì'],
            'ko' => ['ko'],
            'chua co' => ['chưa có'],
            'english none' => ['None'],
            'n slash a' => ['N/A'],
            'dash' => ['-'],
            'zero' => ['0'],
        ];
    }

    #[DataProvider('negationTermProvider')]
    public function test_a_negation_only_allergy_is_not_stored(string $term): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.health.update'), $this->payload([
            'allergies' => [$term],
        ]));

        $this->assertSame([], UserHealthProfile::where('MaKH', $user->MaKH)->first()->allergies, $term);
    }

    public function test_known_terms_drops_negation_placeholders(): void
    {
        // The reader is cleaned too, not just the writer: rows written before the request
        // filter existed still carry the junk.
        $user = User::factory()->create();

        $profile = UserHealthProfile::create([
            'MaKH' => $user->MaKH,
            'allergies' => ['Không', 'Paracetamol'],
            'consented_at' => now(),
        ]);

        $this->assertSame(['paracetamol'], $profile->knownTerms());
    }

    public function test_a_negation_only_profile_does_not_suppress_the_history_step(): void
    {
        $user = User::factory()->create(['role' => 'User', 'status' => 'active']);

        UserHealthProfile::create([
            'MaKH' => $user->MaKH,
            'allergies' => ['Không'],
            'consented_at' => now(),
        ]);

        $this->actingAs($user)->get(route('health.check'));
        $session = TriageSession::where('MaKH', $user->MaKH)->inProgress()->latest('MaSL')->firstOrFail();

        foreach ([1 => 'đau đầu', 2 => 'hôm nay', 3 => 'chưa dùng gì'] as $step => $answer) {
            $this->actingAs($user)->post(route('health.answer'), [
                'triage_session_id' => $session->MaSL,
                'step' => $step,
                'answer' => $answer,
            ]);
        }

        // Step 4 must be the next question, not skipped as "already known".
        $this->actingAs($user)
            ->get(route('health.check'))
            ->assertOk()
            ->assertSee('Anh/chị có bệnh nền, dị ứng thuốc, hoặc đang mang thai / cho con bú không ạ?', false);
    }
}
