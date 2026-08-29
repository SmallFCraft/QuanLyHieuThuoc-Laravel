<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChatMessage;
use App\Models\TriageSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI-drafted pharmacist reply.
 *
 * The invariant under test: a draft is never persisted and never reaches the
 * customer on its own. The pharmacist stays the validator.
 */
class TriageAiDraftTest extends TestCase
{
    use RefreshDatabase;

    protected User $pharmacist;
    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Only in the AI test files: an un-faked provider call must fail loudly here
        // rather than be swallowed by the degrade path.
        Http::preventStrayRequests();

        config()->set('chatbot.ai.triage.pharmacist_draft', true);

        $this->pharmacist = User::factory()->create(['role' => 'Pharmacist', 'status' => 'active']);
        $this->customer = User::factory()->create(['role' => 'User', 'status' => 'active']);
    }

    protected function fakeProviderReply(string $content): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
        ])]);
    }

    protected function pendingSession(array $attributes = []): TriageSession
    {
        return TriageSession::create(array_merge([
            'MaKH' => $this->customer->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'pending_pharmacist',
            'outcome' => 'pharmacist_review',
            'advice_given' => 'Thông tin chưa đủ để định hướng an toàn.',
            'safety_net' => 'Đi khám ngay nếu sốt trên 39 độ quá 3 ngày.',
            'matched_symptoms' => ['fever'],
            'triggered_red_flags' => [],
            'collected_data' => ['raw_symptom_text' => 'toi bi sot va dau dau'],
            'pharmacist_id' => $this->pharmacist->MaKH,
        ], $attributes));
    }

    public function test_flag_off_hides_the_button_and_refuses_the_route(): void
    {
        config()->set('chatbot.ai.triage.pharmacist_draft', false);

        $session = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->get(route('pharmacist.triage.show', $session->MaSL))
            ->assertOk()
            ->assertDontSee(__('messages.pharmacist.btn_ai_draft'));

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.draft', $session->MaSL))
            ->assertSessionHasErrors('pharmacist_note');

        Http::assertNothingSent();
    }

    public function test_a_draft_prefills_the_textarea_without_persisting_anything(): void
    {
        $this->fakeProviderReply('Dạ, với tình trạng anh/chị mô tả, em khuyên nên nghỉ ngơi và theo dõi thêm.');

        $session = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.draft', $session->MaSL))
            ->assertRedirect(route('pharmacist.triage.show', $session->MaSL))
            ->assertSessionHas('success', __('messages.pharmacist.ai_draft_success'));

        $session->refresh();

        $this->assertNull($session->pharmacist_note, 'A draft must never be persisted.');
        $this->assertSame('pending_pharmacist', $session->status);
        $this->assertSame(0, ChatMessage::count(), 'A draft must never reach the chat thread.');

        // The draft comes back through withInput(), so old() fills the existing form.
        $this->actingAs($this->pharmacist)
            ->get(route('pharmacist.triage.show', $session->MaSL))
            ->assertOk()
            ->assertSee('em khuyên nên nghỉ ngơi', false);
    }

    public function test_the_prompt_carries_no_free_text_and_no_profile_data(): void
    {
        $this->fakeProviderReply('Dạ, em xin phép trả lời anh/chị như sau.');

        \App\Models\UserHealthProfile::create([
            'MaKH' => $this->customer->MaKH,
            'allergies' => ['penicillin'],
            'chronic_conditions' => ['loét dạ dày'],
            'current_medications' => ['omeprazole'],
            'consented_at' => now(),
        ]);

        $session = $this->pendingSession();

        $this->actingAs($this->pharmacist)->post(route('pharmacist.triage.draft', $session->MaSL));

        Http::assertSent(function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            // The customer's own words stay out of the prompt.
            $this->assertStringNotContainsString('toi bi sot va dau dau', $body);
            // Encrypted, purpose-limited profile data never leaves the system.
            $this->assertStringNotContainsString('penicillin', $body);
            $this->assertStringNotContainsString('loét dạ dày', $body);
            $this->assertStringNotContainsString('omeprazole', $body);

            return true;
        });
    }

    public function test_an_unclaimed_case_is_refused_before_any_provider_call(): void
    {
        $session = $this->pendingSession(['pharmacist_id' => null]);

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.draft', $session->MaSL))
            ->assertSessionHasErrors('pharmacist_note');

        Http::assertNothingSent();
    }

    public function test_a_case_claimed_by_another_pharmacist_is_refused(): void
    {
        $other = User::factory()->create(['role' => 'Pharmacist', 'status' => 'active']);
        $session = $this->pendingSession(['pharmacist_id' => $other->MaKH]);

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.draft', $session->MaSL))
            ->assertSessionHasErrors('pharmacist_note');

        Http::assertNothingSent();
    }

    public function test_an_already_reviewed_case_is_refused(): void
    {
        $session = $this->pendingSession([
            'status' => 'reviewed',
            'pharmacist_note' => 'Đã trả lời.',
            'reviewed_at' => now(),
        ]);

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.draft', $session->MaSL))
            ->assertSessionHasErrors('pharmacist_note');

        Http::assertNothingSent();
    }

    public function test_a_session_outside_the_queue_is_not_reachable(): void
    {
        $session = $this->pendingSession(['status' => 'in_progress', 'outcome' => null]);

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.draft', $session->MaSL))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_a_provider_failure_leaves_the_form_blank(): void
    {
        Http::fake(['*' => Http::response('gateway down', 502)]);

        $session = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.draft', $session->MaSL))
            ->assertSessionHasErrors('pharmacist_note');

        $this->assertNull($session->fresh()->pharmacist_note);
    }

    public function test_an_empty_provider_reply_is_treated_as_a_failure(): void
    {
        $this->fakeProviderReply('   ');

        $session = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->post(route('pharmacist.triage.draft', $session->MaSL))
            ->assertSessionHasErrors('pharmacist_note');
    }

    public function test_markdown_is_stripped_because_the_textarea_renders_plain_text(): void
    {
        $this->fakeProviderReply("## Trả lời\n\n**Dạ**, anh/chị nên `nghỉ ngơi` và [đi khám](http://x.test).");

        $session = $this->pendingSession();

        $this->actingAs($this->pharmacist)->post(route('pharmacist.triage.draft', $session->MaSL));

        $draft = session('_old_input.pharmacist_note');

        $this->assertStringNotContainsString('##', $draft);
        $this->assertStringNotContainsString('**', $draft);
        $this->assertStringNotContainsString('`', $draft);
        $this->assertStringNotContainsString('http://x.test', $draft);
        $this->assertStringContainsString('đi khám', $draft);
    }

    public function test_the_audit_row_records_provenance_but_no_content(): void
    {
        $this->fakeProviderReply('Dạ, em trả lời anh/chị như sau.');

        $session = $this->pendingSession();

        $this->actingAs($this->pharmacist)->post(route('pharmacist.triage.draft', $session->MaSL));

        $log = AuditLog::where('action', 'triage_ai_draft')->firstOrFail();
        $values = $log->new_values;

        $this->assertSame('ok', $values['ai_status']);
        $this->assertSame('pharmacist_review', $values['outcome']);
        $this->assertArrayHasKey('ai_provider_model', $values);
        $this->assertArrayHasKey('processing_time_ms', $values);

        $encoded = json_encode($values, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('em trả lời anh/chị', $encoded);
        $this->assertStringNotContainsString('toi bi sot', $encoded);
    }

    public function test_a_plain_user_cannot_reach_the_draft_route(): void
    {
        $session = $this->pendingSession();

        $this->actingAs($this->customer)
            ->post(route('pharmacist.triage.draft', $session->MaSL))
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
