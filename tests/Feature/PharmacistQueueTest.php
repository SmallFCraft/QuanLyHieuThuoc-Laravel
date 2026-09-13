<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\ChatMessage;
use App\Models\TriageSession;
use App\Models\User;
use App\Models\UserHealthProfile;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The pharmacist queue is the only place a real human closes a triage case.
 * These tests pin the access boundary, the ordering promise (longest wait first)
 * and the rule that a reviewed case is never silently overwritten.
 */
class PharmacistQueueTest extends TestCase
{
    use RefreshDatabase;

    protected User $pharmacist;
    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

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

    // --- Access control ---

    public function test_pharmacist_can_open_the_queue(): void
    {
        $this->actingAs($this->pharmacist)
            ->get(route('pharmacist.triage.index'))
            ->assertOk()
            ->assertSee(__('messages.pharmacist.queue_title'));
    }

    public function test_plain_user_is_forbidden(): void
    {
        $this->actingAs($this->customer)
            ->get(route('pharmacist.triage.index'))
            ->assertForbidden();
    }

    public function test_administrator_is_also_forbidden(): void
    {
        // Running the shop is not the same job as answering clinical questions.
        $admin = User::factory()->create(['role' => 'Administrator', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('pharmacist.triage.index'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('pharmacist.triage.index'))->assertRedirect(route('login'));
    }

    public function test_inactive_pharmacist_is_blocked(): void
    {
        $inactive = User::factory()->create(['role' => 'Pharmacist', 'status' => 'inactive']);

        $this->actingAs($inactive)
            ->get(route('pharmacist.triage.index'))
            ->assertRedirect(route('login'));
    }

    // --- Queue contents and ordering ---

    public function test_queue_lists_only_pending_cases(): void
    {
        $pending = $this->pendingSession(['raw_symptom_text' => 'đau đầu âm ỉ nhiều ngày']);
        $completed = $this->makeSession('completed', ['raw_symptom_text' => 'ho nhẹ đã khỏi']);
        $inProgress = $this->makeSession('in_progress', ['raw_symptom_text' => 'chưa trả lời xong']);

        $response = $this->actingAs($this->pharmacist)
            ->get(route('pharmacist.triage.index'))
            ->assertOk();

        $response->assertSee(route('pharmacist.triage.show', $pending->MaSL), false);
        $response->assertDontSee(route('pharmacist.triage.show', $completed->MaSL), false);
        $response->assertDontSee(route('pharmacist.triage.show', $inProgress->MaSL), false);
    }

    public function test_oldest_case_appears_first(): void
    {
        $newer = $this->pendingSession(['raw_symptom_text' => 'ca moi vao'], now()->subMinutes(5));
        $older = $this->pendingSession(['raw_symptom_text' => 'ca cho lau nhat'], now()->subHours(3));

        $html = $this->actingAs($this->pharmacist)
            ->get(route('pharmacist.triage.index'))
            ->assertOk()
            ->getContent();

        $olderPos = strpos($html, route('pharmacist.triage.show', $older->MaSL));
        $newerPos = strpos($html, route('pharmacist.triage.show', $newer->MaSL));

        $this->assertNotFalse($olderPos);
        $this->assertNotFalse($newerPos);
        $this->assertLessThan($newerPos, $olderPos, 'Whoever waited longest must be handled first.');
    }

    public function test_empty_queue_shows_a_decent_message(): void
    {
        $this->actingAs($this->pharmacist)
            ->get(route('pharmacist.triage.index'))
            ->assertOk()
            ->assertSee(__('messages.pharmacist.queue_empty'));
    }

    // --- Reply ---

    public function test_reply_records_the_review(): void
    {
        $session = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.reply', $session->MaSL), [
                'pharmacist_note' => 'Anh/chị có thể dùng paracetamol 500mg khi đau, tối đa 4 lần/ngày.',
            ])
            ->assertRedirect(route('pharmacist.triage.index'));

        $session->refresh();

        $this->assertSame($this->pharmacist->MaKH, $session->pharmacist_id);
        $this->assertStringContainsString('paracetamol', $session->pharmacist_note);
        $this->assertNotNull($session->reviewed_at);
        $this->assertSame('reviewed', $session->status);
    }

    public function test_reply_writes_a_chat_message_marked_as_human_reviewed(): void
    {
        $session = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.reply', $session->MaSL), [
                'pharmacist_note' => 'Em đã xem, anh/chị theo dõi thêm 2 ngày nhé.',
            ]);

        $message = ChatMessage::where('session_id', $session->session_id)
            ->where('role', 'assistant')
            ->latest('MaTN')
            ->first();

        $this->assertNotNull($message);
        $this->assertTrue($message->metadata['reviewed_by_pharmacist']);
        $this->assertSame($this->pharmacist->MaKH, $message->metadata['pharmacist_id']);
        $this->assertSame($session->MaSL, $message->metadata['triage_session_id']);
    }

    public function test_reply_on_an_already_reviewed_case_is_refused(): void
    {
        $session = $this->pendingSession();
        $session->update([
            'status' => 'reviewed',
            'pharmacist_note' => 'Ghi chú gốc của dược sĩ trước.',
            'pharmacist_id' => $this->pharmacist->MaKH,
            'reviewed_at' => now()->subHour(),
        ]);

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.reply', $session->MaSL), [
                'pharmacist_note' => 'Ghi chú mới không được phép ghi đè.',
            ])
            ->assertSessionHasErrors('pharmacist_note');

        $this->assertSame('Ghi chú gốc của dược sĩ trước.', $session->fresh()->pharmacist_note);
    }

    public function test_reply_without_a_note_is_rejected(): void
    {
        $session = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.reply', $session->MaSL), ['pharmacist_note' => ''])
            ->assertSessionHasErrors('pharmacist_note');

        $this->assertSame('pending_pharmacist', $session->fresh()->status);
    }

    // --- Escalate ---

    public function test_escalate_refers_to_a_doctor_and_drops_product_suggestions(): void
    {
        $session = $this->pendingSession();
        $session->update([
            'outcome' => 'pharmacist_review',
            'suggested_products' => [['name' => 'Paracetamol 500mg', 'price' => 5000]],
        ]);

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.escalate', $session->MaSL), [
                'pharmacist_note' => 'Triệu chứng kéo dài kèm sụt cân, anh/chị cần bác sĩ khám trực tiếp.',
            ])
            ->assertRedirect(route('pharmacist.triage.index'));

        $session->refresh();

        $this->assertSame('refer_doctor', $session->outcome);
        $this->assertSame([], $session->suggested_products);
        $this->assertSame('reviewed', $session->status);
    }

    // --- Health profile privacy ---

    public function test_detail_hides_profile_data_without_consent(): void
    {
        UserHealthProfile::create([
            'MaKH' => $this->customer->MaKH,
            'allergies' => ['dị ứng penicillin'],
            'chronic_conditions' => ['loét dạ dày'],
            'consented_at' => null,
        ]);

        $session = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->get(route('pharmacist.triage.show', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.pharmacist.profile_no_consent'))
            ->assertDontSee('dị ứng penicillin')
            ->assertDontSee('loét dạ dày');

        $this->assertSame(0, AuditLog::where('action', 'health_profile_accessed')->count());
    }

    public function test_detail_shows_consented_profile_and_audits_the_access(): void
    {
        UserHealthProfile::create([
            'MaKH' => $this->customer->MaKH,
            'allergies' => ['dị ứng penicillin'],
            'consented_at' => now(),
        ]);

        $session = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->get(route('pharmacist.triage.show', $session->MaSL))
            ->assertOk()
            ->assertSee('dị ứng penicillin');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'health_profile_accessed',
            'MaKH' => $this->pharmacist->MaKH,
            'model_type' => UserHealthProfile::class,
        ]);
    }

    // --- Claim ---

    public function test_claim_blocks_a_second_pharmacist(): void
    {
        $other = User::factory()->create(['role' => 'Pharmacist', 'status' => 'active']);
        $session = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.claim', $session->MaSL))
            ->assertRedirect(route('pharmacist.triage.index'));

        $this->assertSame($this->pharmacist->MaKH, $session->fresh()->pharmacist_id);

        $this->actingAs($other)
            ->post(route('pharmacist.triage.reply', $session->MaSL), [
                'pharmacist_note' => 'Dược sĩ khác cố trả lời cùng ca.',
            ])
            ->assertSessionHasErrors('pharmacist_note');

        $this->assertNull($session->fresh()->pharmacist_note);
    }

    // --- Past sessions on the detail page ---

    public function test_show_loads_recent_sessions_for_same_customer(): void
    {
        $past = $this->makeSession('reviewed', ['raw_symptom_text' => 'Triệu chứng cũ']);
        $current = $this->pendingSession(['raw_symptom_text' => 'Triệu chứng mới']);

        $this->actingAs($this->pharmacist)
            ->get(route('pharmacist.triage.show', $current->MaSL))
            ->assertOk()
            ->assertViewHas('pastSessions', fn ($sessions) => $sessions->contains('MaSL', $past->MaSL));
    }

    public function test_show_excludes_other_customers_past_sessions(): void
    {
        $stranger = User::factory()->create(['role' => 'User', 'status' => 'active']);
        $strangerSession = TriageSession::create([
            'MaKH' => $stranger->MaKH,
            'status' => 'reviewed',
            'collected_data' => ['raw_symptom_text' => 'Ca của người khác'],
        ]);

        $current = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->get(route('pharmacist.triage.show', $current->MaSL))
            ->assertOk()
            ->assertViewHas('pastSessions', fn ($sessions) => !$sessions->contains('MaSL', $strangerSession->MaSL));
    }

    public function test_show_excludes_sessions_never_handed_to_a_pharmacist(): void
    {
        $inProgress = $this->makeSession('in_progress', ['raw_symptom_text' => 'Chưa trả lời xong']);
        $current = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->get(route('pharmacist.triage.show', $current->MaSL))
            ->assertOk()
            ->assertViewHas('pastSessions', fn ($sessions) => !$sessions->contains('MaSL', $inProgress->MaSL));
    }

    // Note: triage_sessions.MaKH is NOT NULL, so the empty-collection branch of
    // show() is unreachable through the DB and is left untested.

    // --- Queue metrics ---

    public function test_index_reports_pending_metrics_from_the_queue(): void
    {
        $older = $this->pendingSession([], now()->subHours(4));
        $this->pendingSession([], now()->subMinutes(3));
        $this->makeSession('reviewed', [], now()->subDay());

        $this->actingAs($this->pharmacist)
            ->get(route('pharmacist.triage.index'))
            ->assertOk()
            ->assertViewHas('pendingCount', 2)
            ->assertViewHas(
                'oldestPendingAt',
                fn ($oldest) => $oldest instanceof Carbon
                    && $oldest->equalTo($older->created_at)
            );
    }

    // --- Helpers ---

    protected function pendingSession(array $collected = [], ?\DateTimeInterface $createdAt = null): TriageSession
    {
        return $this->makeSession('pending_pharmacist', $collected, $createdAt);
    }

    protected function makeSession(string $status, array $collected = [], ?\DateTimeInterface $createdAt = null): TriageSession
    {
        $session = TriageSession::create([
            'MaKH' => $this->customer->MaKH,
            'session_id' => (string) Str::uuid(),
            'collected_data' => array_merge([
                'raw_symptom_text' => 'đau đầu kéo dài, chưa rõ nguyên nhân',
                'symptoms' => 'đau đầu',
                'duration_days' => 4,
                'medications_taken' => 'chưa dùng gì',
                'history' => 'không có',
                'other_symptoms' => 'không có thêm',
                'skipped' => [],
            ], $collected),
            'matched_symptoms' => ['headache'],
            'triggered_red_flags' => [
                ['code' => 'headache_persistent', 'name_vi' => 'đau đầu kéo dài', 'severity' => 'urgent'],
            ],
            'outcome' => 'pharmacist_review',
            'advice_given' => 'Em đã chuyển ca này cho dược sĩ xem.',
            'safety_net' => 'Đi khám ngay nếu đau đầu dữ dội hoặc nôn nhiều.',
            'current_step' => 6,
            'entry_point' => 'page',
            'status' => $status,
        ]);

        if ($createdAt) {
            // created_at drives the queue order, so it has to be settable per case.
            $session->forceFill(['created_at' => $createdAt])->save();
        }

        return $session->fresh();
    }
}
