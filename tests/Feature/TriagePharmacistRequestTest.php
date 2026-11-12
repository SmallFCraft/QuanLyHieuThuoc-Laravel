<?php

namespace Tests\Feature;

use App\Models\NhatKyKiemToan;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
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

    protected NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nguoiDung = NguoiDung::factory()->create(['role' => 'User', 'status' => 'active']);
    }

    protected function completedSession(array $attributes = []): PhienSangLoc
    {
        return PhienSangLoc::create(array_merge([
            'MaKH' => $this->nguoiDung->MaKH,
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
        $pharmacist = NguoiDung::factory()->create(['role' => 'Pharmacist', 'status' => 'active']);
        $this->completedSession();

        $this->actingAs($pharmacist)
            ->get(route('pharmacist.triage.index'))
            ->assertOk()
            ->assertSee(__('messages.pharmacist.queue_empty'));
    }

    public function test_a_requested_case_appears_in_the_queue(): void
    {
        $pharmacist = NguoiDung::factory()->create(['role' => 'Pharmacist', 'status' => 'active']);
        $phien = $this->completedSession();

        $this->actingAs($this->nguoiDung)
            ->post(route('health.requestPharmacist', $phien->MaSL))
            ->assertRedirect(route('health.result', $phien->MaSL))
            ->assertSessionHas('success', __('messages.health.pharmacist_requested'));

        $this->assertSame('pending_pharmacist', $phien->fresh()->status);

        $this->actingAs($pharmacist)
            ->get(route('pharmacist.triage.index'))
            ->assertOk()
            ->assertDontSee(__('messages.pharmacist.queue_empty'));
    }

    public function test_a_self_care_case_can_also_ask_for_a_pharmacist(): void
    {
        $phien = $this->completedSession([
            'outcome' => 'self_care',
            'safety_net' => 'Đi khám nếu sốt quá 3 ngày.',
            'follow_up_at' => now()->addDays(3),
        ]);

        $this->actingAs($this->nguoiDung)->post(route('health.requestPharmacist', $phien->MaSL));

        $phien = $phien->fresh();

        $this->assertSame('pending_pharmacist', $phien->status);
        // A queued case waits on a human, so the automated check-in is cleared.
        $this->assertNull($phien->follow_up_at);
    }

    public function test_an_emergency_cannot_be_queued(): void
    {
        $phien = $this->completedSession([
            'outcome' => 'emergency',
            'advice_given' => 'Anh/chị hãy gọi ngay 115.',
        ]);

        $this->actingAs($this->nguoiDung)
            ->post(route('health.requestPharmacist', $phien->MaSL))
            ->assertSessionHas('success', __('messages.health.pharmacist_request_unavailable'));

        $this->assertSame('completed', $phien->fresh()->status);
    }

    public function test_a_second_request_is_a_no_op(): void
    {
        $phien = $this->completedSession();

        $this->actingAs($this->nguoiDung)->post(route('health.requestPharmacist', $phien->MaSL));
        $this->actingAs($this->nguoiDung)
            ->post(route('health.requestPharmacist', $phien->MaSL))
            ->assertSessionHas('success', __('messages.health.pharmacist_request_unavailable'));

        $this->assertSame('pending_pharmacist', $phien->fresh()->status);
        $this->assertSame(1, NhatKyKiemToan::where('action', 'triage_pharmacist_requested')->count());
    }

    public function test_an_in_progress_session_cannot_be_queued(): void
    {
        $phien = $this->completedSession(['status' => 'in_progress', 'outcome' => null]);

        $this->actingAs($this->nguoiDung)->post(route('health.requestPharmacist', $phien->MaSL));

        $this->assertSame('in_progress', $phien->fresh()->status);
    }

    public function test_the_request_is_audited_with_codes_only(): void
    {
        $phien = $this->completedSession(['matched_symptoms' => ['headache']]);

        $this->actingAs($this->nguoiDung)->post(route('health.requestPharmacist', $phien->MaSL));

        $log = NhatKyKiemToan::where('action', 'triage_pharmacist_requested')->firstOrFail();
        $encoded = json_encode($log->new_values, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('headache', $encoded);
        $this->assertStringNotContainsString('dau dau', $encoded);
    }

    public function test_another_user_cannot_queue_someone_elses_session(): void
    {
        $phien = $this->completedSession();
        $other = NguoiDung::factory()->create(['role' => 'User', 'status' => 'active']);

        $this->actingAs($other)
            ->post(route('health.requestPharmacist', $phien->MaSL))
            ->assertNotFound();

        $this->assertSame('completed', $phien->fresh()->status);
    }

    public function test_a_guest_cannot_queue_a_session(): void
    {
        $phien = $this->completedSession();

        $this->post(route('health.requestPharmacist', $phien->MaSL))
            ->assertRedirect(route('login'));
    }
}
