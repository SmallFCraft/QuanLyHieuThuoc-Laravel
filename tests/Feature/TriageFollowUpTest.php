<?php

namespace Tests\Feature;

use App\Mail\TriageFollowUpMail;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\ChatMessage;
use App\Models\TriageSession;
use App\Models\User;
use App\Services\TriageFollowUpService;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Safety-netting is a pharmacy-practice requirement, not a nice-to-have: a
 * self-care outcome must be re-checked, and "not improved" must escalate to a
 * doctor without offering more medicine.
 */
class TriageFollowUpTest extends TestCase
{
    use RefreshDatabase;

    protected TriageFollowUpService $service;
    protected Category $category;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Thuốc giảm đau - Hạ sốt',
            'description' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(MedicalKnowledgeSeeder::class);

        $this->service = app(TriageFollowUpService::class);

        $this->user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
            'birth_date' => now()->subYears(34)->toDateString(),
        ]);
    }

    // --- Scheduling ---

    public function test_only_self_care_sessions_are_scheduled_for_follow_up(): void
    {
        // Drive the real finalize() so the scheduling rule is asserted, not the fixture.
        $triage = app(\App\Services\MedicalTriageService::class);

        $selfCare = $triage->finalize(
            $this->triageSession(['outcome' => null, 'status' => 'in_progress', 'follow_up_at' => null]),
            ['outcome' => 'self_care', 'advice_given' => 'Nghỉ ngơi.', 'safety_net' => 'Đau nặng thì đi khám.']
        );
        $refer = $triage->finalize(
            $this->triageSession(['outcome' => null, 'status' => 'in_progress', 'follow_up_at' => null]),
            ['outcome' => 'refer_doctor']
        );
        $emergency = $triage->finalize(
            $this->triageSession(['outcome' => null, 'status' => 'in_progress', 'follow_up_at' => null]),
            ['outcome' => 'emergency']
        );

        $this->assertNotNull($selfCare->follow_up_at);
        $this->assertNull($refer->follow_up_at);
        $this->assertNull($emergency->follow_up_at);
    }

    public function test_dispatch_due_only_picks_sessions_past_their_due_date(): void
    {
        Mail::fake();

        $due = $this->triageSession(['follow_up_at' => now()->subHour()]);
        $notYet = $this->triageSession(['follow_up_at' => now()->addDays(2)]);
        $inProgress = $this->triageSession([
            'follow_up_at' => now()->subHour(),
            'status' => 'in_progress',
        ]);

        $sent = $this->service->dispatchDue();

        $this->assertSame(1, $sent);
        $this->assertNull($due->fresh()->follow_up_at);
        $this->assertNotNull($notYet->fresh()->follow_up_at);
        $this->assertNotNull($inProgress->fresh()->follow_up_at);
    }

    public function test_follow_up_is_not_asked_twice(): void
    {
        Mail::fake();
        $this->triageSession(['follow_up_at' => now()->subHour()]);

        $this->assertSame(1, $this->service->dispatchDue());
        $this->assertSame(0, $this->service->dispatchDue());
        Mail::assertQueued(TriageFollowUpMail::class, 1);
    }

    public function test_dispatch_due_mails_the_customer_and_logs_a_chat_message(): void
    {
        Mail::fake();
        $session = $this->triageSession(['follow_up_at' => now()->subHour()]);

        $this->service->dispatchDue();

        Mail::assertQueued(TriageFollowUpMail::class);
        $this->assertDatabaseHas('chat_messages', [
            'MaKH' => $this->user->MaKH,
            'session_id' => $session->session_id,
            'role' => 'assistant',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'triage_follow_up_sent']);
    }

    public function test_sessions_without_a_chat_thread_still_get_the_mail(): void
    {
        Mail::fake();
        $this->triageSession(['follow_up_at' => now()->subHour(), 'session_id' => null]);

        $this->assertSame(1, $this->service->dispatchDue());
        Mail::assertQueued(TriageFollowUpMail::class);
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_console_command_dispatches_follow_ups(): void
    {
        Mail::fake();
        $this->triageSession(['follow_up_at' => now()->subHour()]);

        $this->artisan('triage:follow-up')->assertSuccessful();

        Mail::assertQueued(TriageFollowUpMail::class);
    }

    // --- The core safety-netting rule ---

    public function test_not_improved_advises_a_doctor_and_returns_no_products(): void
    {
        $session = $this->triageSession(['follow_up_at' => null]);

        $result = $this->service->recordResponse($session, false);

        $this->assertFalse($result['improved']);
        $this->assertTrue($result['refer_doctor']);
        // Hard assertion: a customer who did not get better must never be upsold.
        $this->assertSame([], $result['products']);
        $this->assertSame(__('messages.reminder.response_not_improved'), $result['message']);
        // The wording itself is the safety net, so assert the copy, not just the key.
        // Read from lang/vi/reminder.php directly: messages.php wiring is owned elsewhere.
        $this->assertStringContainsString('đi khám', $this->reminderLang('response_not_improved'));
        $this->assertSame('refer_doctor', $session->fresh()->outcome);

        $message = ChatMessage::where('session_id', $session->session_id)->latest('MaTN')->firstOrFail();
        $this->assertSame('assistant', $message->role);
        $this->assertEmpty($message->products ?? []);

        $this->assertDatabaseHas('audit_logs', ['action' => 'triage_follow_up_not_improved']);
    }

    public function test_improved_is_acknowledged_and_audited(): void
    {
        $session = $this->triageSession(['follow_up_at' => null]);

        $result = $this->service->recordResponse($session, true);

        $this->assertTrue($result['improved']);
        $this->assertFalse($result['refer_doctor']);
        $this->assertSame([], $result['products']);
        $this->assertSame(__('messages.reminder.response_improved'), $result['message']);
        $this->assertStringContainsString('liệu trình', $this->reminderLang('response_improved'));
        $this->assertSame('self_care', $session->fresh()->outcome);

        $this->assertDatabaseHas('audit_logs', ['action' => 'triage_follow_up_improved']);
    }

    public function test_both_branches_write_an_audit_record(): void
    {
        $this->service->recordResponse($this->triageSession(['follow_up_at' => null]), true);
        $this->service->recordResponse($this->triageSession(['follow_up_at' => null]), false);

        $this->assertSame(1, AuditLog::where('action', 'triage_follow_up_improved')->count());
        $this->assertSame(1, AuditLog::where('action', 'triage_follow_up_not_improved')->count());
    }

    // --- Ownership ---

    public function test_a_customer_cannot_answer_someone_elses_session(): void
    {
        $other = User::factory()->create(['role' => 'User', 'status' => 'active']);
        $session = $this->triageSession(['follow_up_at' => null]);

        $this->actingAs($other)
            ->post(route('health.followup', $session->MaSL), ['improved' => 0])
            ->assertRedirect();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'triage_follow_up_not_improved']);
        $this->assertSame('self_care', $session->fresh()->outcome);
    }

    public function test_owner_can_answer_through_the_route(): void
    {
        $session = $this->triageSession(['follow_up_at' => null]);

        $this->actingAs($this->user)
            ->post(route('health.followup', $session->MaSL), ['improved' => 0])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['action' => 'triage_follow_up_not_improved']);
    }

    public function test_improved_flag_is_required(): void
    {
        $session = $this->triageSession(['follow_up_at' => null]);

        $this->actingAs($this->user)
            ->post(route('health.followup', $session->MaSL), [])
            ->assertSessionHasErrors('improved');
    }

    public function test_guest_cannot_answer_a_follow_up(): void
    {
        $session = $this->triageSession(['follow_up_at' => null]);

        $this->post(route('health.followup', $session->MaSL), ['improved' => 1])
            ->assertRedirect(route('login'));
    }

    // --- helpers ---

    /**
     * lang/vi/reminder.php is not wired into messages.php yet (that file is owned
     * by another change), so assert the copy from the source file.
     */
    private function reminderLang(string $key): string
    {
        static $lines = null;
        $lines ??= require base_path('lang/vi/reminder.php');

        return $lines[$key];
    }

    private function triageSession(array $attributes = []): TriageSession
    {
        return TriageSession::create(array_merge([
            'MaKH' => $this->user->MaKH,
            'session_id' => (string) Str::uuid(),
            'collected_data' => ['raw_symptom_text' => 'đau đầu nhẹ'],
            'outcome' => 'self_care',
            'advice_given' => 'Nghỉ ngơi, uống đủ nước.',
            'safety_net' => 'Đi khám ngay nếu đau đầu dữ dội.',
            'suggested_products' => [],
            'current_step' => 5,
            'entry_point' => 'page',
            'status' => 'completed',
        ], $attributes));
    }
}
