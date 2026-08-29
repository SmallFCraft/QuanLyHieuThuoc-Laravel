<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TriageSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pharmacist queue as an opt-in side channel.
 *
 * AI is the primary layer of the feature: finalising a session never queues it, and
 * a human is brought in only when the customer asks. Emergencies are excluded —
 * their only action is calling 115.
 */
class TriagePharmacistRequestTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'User', 'status' => 'active']);
    }

    protected function completedSession(array $attributes = []): TriageSession
    {
        return TriageSession::create(array_merge([
            'MaKH' => $this->user->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'pharmacist_review',
            'advice_given' => 'Anh/chị theo dõi thêm và đi khám nếu nặng lên.',
            'collected_data' => ['raw_symptom_task' => 'x', 'raw_symptom_text' => 'dau dau'],
        ], $attributes));
    }

    public function test_the_queue_is_empty_until_a_customer_asks(): void
    {
        $pharmacist = User::factory()->create(['role' => 'Pharmacist', 'status' => 'active']);
        $this->completedSession();

        $this->actingAs($pharmacist)
            ->get(route('pharmacist.triage.index'))
            ->assertOk()
            ->assertSee(__('messages.pharmacist.queue_empty'));
    }

    public function test_a_requested_case_appears_in_the_queue(): void
    {
        $pharmacist = User::factory()->create(['role' => 'Pharmacist', 'status' => 'active']);
        $session = $this->completedSession();

        $this->actingAs($this->user)
            ->post(route('health.requestPharmacist', $session->MaSL))
            ->assertRedirect(route('health.result', $session->MaSL))
            ->assertSessionHas('success', __('messages.health.pharmacist_requested'));

        $this->assertSame('pending_pharmacist', $session->fresh()->status);

        $this->actingAs($pharmacist)
            ->get(route('pharmacist.triage.index'))
            ->assertOk()
            ->assertDontSee(__('messages.pharmacist.queue_empty'));
    }

    public function test_a_self_care_case_can_also_ask_for_a_pharmacist(): void
    {
        $session = $this->completedSession([
            'outcome' => 'self_care',
            'safety_net' => 'Đi khám nếu sốt quá 3 ngày.',
            'follow_up_at' => now()->addDays(3),
        ]);

        $this->actingAs($this->user)->post(route('health.requestPharmacist', $session->MaSL));

        $session = $session->fresh();

        $this->assertSame('pending_pharmacist', $session->status);
        // A queued case waits on a human, so the automated check-in is cleared.
        $this->assertNull($session->follow_up_at);
    }

    public function test_an_emergency_cannot_be_queued(): void
    {
        $session = $this->completedSession([
            'outcome' => 'emergency',
            'advice_given' => 'Anh/chị hãy gọi ngay 115.',
        ]);

        $this->actingAs($this->user)
            ->post(route('health.requestPharmacist', $session->MaSL))
            ->assertSessionHas('success', __('messages.health.pharmacist_request_unavailable'));

        $this->assertSame('completed', $session->fresh()->status);
    }

    public function test_a_second_request_is_a_no_op(): void
    {
        $session = $this->completedSession();

        $this->actingAs($this->user)->post(route('health.requestPharmacist', $session->MaSL));
        $this->actingAs($this->user)
            ->post(route('health.requestPharmacist', $session->MaSL))
            ->assertSessionHas('success', __('messages.health.pharmacist_request_unavailable'));

        $this->assertSame('pending_pharmacist', $session->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'triage_pharmacist_requested')->count());
    }

    public function test_an_in_progress_session_cannot_be_queued(): void
    {
        $session = $this->completedSession(['status' => 'in_progress', 'outcome' => null]);

        $this->actingAs($this->user)->post(route('health.requestPharmacist', $session->MaSL));

        $this->assertSame('in_progress', $session->fresh()->status);
    }

    public function test_the_request_is_audited_with_codes_only(): void
    {
        $session = $this->completedSession(['matched_symptoms' => ['headache']]);

        $this->actingAs($this->user)->post(route('health.requestPharmacist', $session->MaSL));

        $log = AuditLog::where('action', 'triage_pharmacist_requested')->firstOrFail();
        $encoded = json_encode($log->new_values, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('headache', $encoded);
        $this->assertStringNotContainsString('dau dau', $encoded);
    }

    public function test_another_user_cannot_queue_someone_elses_session(): void
    {
        $session = $this->completedSession();
        $other = User::factory()->create(['role' => 'User', 'status' => 'active']);

        $this->actingAs($other)
            ->post(route('health.requestPharmacist', $session->MaSL))
            ->assertNotFound();

        $this->assertSame('completed', $session->fresh()->status);
    }

    public function test_a_guest_cannot_queue_a_session(): void
    {
        $session = $this->completedSession();

        $this->post(route('health.requestPharmacist', $session->MaSL))
            ->assertRedirect(route('login'));
    }
}
