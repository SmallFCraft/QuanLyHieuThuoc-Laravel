<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TriageSession;
use App\Models\User;
use App\Services\TriageAiAdviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI rewrite of the pharmacist-written self-care advice.
 *
 * The invariants under test: `advice_given` is never overwritten, only `self_care`
 * is eligible, and a rewrite that drops a numeric threshold is discarded.
 */
class TriageAiAdviceTest extends TestCase
{
    use RefreshDatabase;

    protected const SOURCE_ADVICE = "Nghỉ ngơi, uống nhiều nước, mặc thoáng.\nĐo lại nhiệt độ mỗi 4–6 giờ.";
    protected const SOURCE_SAFETY = 'Đi khám ngay nếu sốt trên 39 độ không hạ hoặc sốt quá 3 ngày.';

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config()->set('chatbot.ai.triage.advice_rewrite', true);

        $this->user = User::factory()->create(['role' => 'User', 'status' => 'active']);
    }

    protected function fakeProviderReply(string $content): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
        ])]);
    }

    protected function selfCareSession(array $attributes = []): TriageSession
    {
        return TriageSession::create(array_merge([
            'MaKH' => $this->user->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'self_care',
            'advice_given' => self::SOURCE_ADVICE,
            'safety_net' => self::SOURCE_SAFETY,
            'collected_data' => ['raw_symptom_text' => 'toi bi sot nhe'],
        ], $attributes));
    }

    protected function rewrite(TriageSession $session): TriageSession
    {
        app(TriageAiAdviceService::class)->rewrite($session);

        return $session->refresh();
    }

    public function test_flag_off_sends_nothing(): void
    {
        config()->set('chatbot.ai.triage.advice_rewrite', false);

        $session = $this->rewrite($this->selfCareSession());

        Http::assertNothingSent();
        $this->assertNull($session->ai_advice);
        $this->assertNull($session->ai_status);
    }

    public function test_a_good_rewrite_is_stored_beside_the_original(): void
    {
        $this->fakeProviderReply(implode("\n", [
            'LỜI KHUYÊN:',
            'Anh/chị nghỉ ngơi, uống nhiều nước và mặc thoáng nhé. Nhớ đo lại nhiệt độ mỗi 4–6 giờ ạ.',
            '',
            'NGƯỠNG CẦN ĐI KHÁM:',
            'Anh/chị đi khám ngay nếu sốt trên 39 độ không hạ, hoặc sốt quá 3 ngày ạ.',
        ]));

        $session = $this->rewrite($this->selfCareSession());

        $this->assertSame('ok', $session->ai_status);
        $this->assertStringContainsString('nghỉ ngơi, uống nhiều nước', $session->ai_advice);
        $this->assertStringContainsString('39 độ', $session->ai_safety_net);

        // The deterministic record is untouched.
        $this->assertSame(self::SOURCE_ADVICE, $session->advice_given);
        $this->assertSame(self::SOURCE_SAFETY, $session->safety_net);
    }

    public function test_an_echoed_prompt_preamble_is_stripped_from_the_advice(): void
    {
        // Observed against the live provider: the model repeated the prompt's
        // "Kết luận hệ thống:" line before the label, and it rendered verbatim on the
        // result page above the actual advice.
        $this->fakeProviderReply(implode("\n", [
            'Kết luận hệ thống: có thể tự chăm sóc tại nhà.',
            '',
            'LỜI KHUYÊN:',
            'Anh/chị nghỉ ngơi, uống nhiều nước và mặc thoáng nhé. Nhớ đo lại nhiệt độ mỗi 4–6 giờ ạ.',
            '',
            'NGƯỠNG CẦN ĐI KHÁM:',
            'Anh/chị đi khám ngay nếu sốt trên 39 độ không hạ, hoặc sốt quá 3 ngày ạ.',
        ]));

        $session = $this->rewrite($this->selfCareSession());

        $this->assertSame('ok', $session->ai_status);
        $this->assertStringNotContainsString('Kết luận hệ thống', $session->ai_advice);
        $this->assertStringStartsWith('Anh/chị nghỉ ngơi', $session->ai_advice);
    }

    public function test_a_rewrite_that_drops_a_threshold_is_rejected(): void
    {
        // "39" and "3" are gone: the safety net has silently lost its trigger.
        $this->fakeProviderReply(implode("\n", [
            'LỜI KHUYÊN:',
            'Anh/chị nghỉ ngơi, uống nhiều nước và mặc thoáng nhé. Đo lại nhiệt độ mỗi 4–6 giờ ạ.',
            '',
            'NGƯỠNG CẦN ĐI KHÁM:',
            'Anh/chị đi khám ngay nếu sốt cao không hạ hoặc sốt kéo dài ạ.',
        ]));

        $session = $this->rewrite($this->selfCareSession());

        $this->assertSame('rejected', $session->ai_status);
        $this->assertNull($session->ai_advice);
        $this->assertSame(self::SOURCE_ADVICE, $session->advice_given);
    }

    public function test_a_json_reply_is_rejected(): void
    {
        $this->fakeProviderReply('{"outcome":"self_care","advice":"uống nước"}');

        $session = $this->rewrite($this->selfCareSession());

        $this->assertSame('rejected', $session->ai_status);
        $this->assertNull($session->ai_advice);
    }

    public function test_a_markdown_reply_is_rejected(): void
    {
        $this->fakeProviderReply(implode("\n", [
            '## LỜI KHUYÊN',
            '**Anh/chị** nghỉ ngơi, uống nhiều nước, mặc thoáng. Đo nhiệt độ mỗi 4–6 giờ.',
            '',
            'NGƯỠNG CẦN ĐI KHÁM:',
            'Đi khám nếu sốt trên 39 độ hoặc quá 3 ngày.',
        ]));

        $session = $this->rewrite($this->selfCareSession());

        $this->assertSame('rejected', $session->ai_status);
    }

    public function test_an_emergency_outcome_is_never_rewritten(): void
    {
        $session = $this->rewrite($this->selfCareSession([
            'outcome' => 'emergency',
            'advice_given' => 'Anh/chị hãy gọi ngay 115 hoặc đến cơ sở y tế gần nhất.',
            'safety_net' => null,
        ]));

        Http::assertNothingSent();
        $this->assertNull($session->ai_advice);
        $this->assertStringContainsString('115', $session->advice_given);
    }

    public function test_a_referral_outcome_is_never_rewritten(): void
    {
        $session = $this->rewrite($this->selfCareSession([
            'outcome' => 'refer_doctor',
            'safety_net' => null,
        ]));

        Http::assertNothingSent();
        $this->assertNull($session->ai_advice);
    }

    public function test_a_pharmacist_review_outcome_is_rewritten_because_the_ai_answer_is_primary(): void
    {
        // The customer now reads the AI answer first and asks for a human only if
        // they want one, so this text is what they actually see.
        $this->fakeProviderReply(implode("\n", [
            'LỜI KHUYÊN:',
            'Anh/chị nghỉ ngơi, uống nhiều nước, mặc thoáng và đo nhiệt độ mỗi 4–6 giờ nhé.',
        ]));

        $session = $this->rewrite($this->selfCareSession([
            'outcome' => 'pharmacist_review',
            'safety_net' => null,
        ]));

        $this->assertSame('ok', $session->ai_status);
        $this->assertStringContainsString('nghỉ ngơi', $session->ai_advice);
        $this->assertSame(self::SOURCE_ADVICE, $session->advice_given);
    }

    public function test_a_queued_case_still_shows_the_human_answer_when_it_arrives(): void
    {
        // The AI rewrite and the pharmacist note coexist: the note is rendered above
        // and is never replaced by machine prose.
        $this->fakeProviderReply(implode("\n", [
            'LỜI KHUYÊN:',
            'Anh/chị nghỉ ngơi, uống nhiều nước, mặc thoáng, đo nhiệt độ mỗi 4–6 giờ.',
        ]));

        $session = $this->rewrite($this->selfCareSession([
            'outcome' => 'pharmacist_review',
            'status' => 'reviewed',
            'safety_net' => null,
            'pharmacist_note' => 'Dạ, em là dược sĩ Hạnh, anh/chị theo dõi thêm 2 ngày nhé.',
            'reviewed_at' => now(),
        ]));

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee('dược sĩ Hạnh', false)
            ->assertSee(__('messages.health.ai_badge'));
    }

    public function test_a_provider_failure_is_recorded_and_the_page_is_unchanged(): void
    {
        Http::fake(['*' => Http::response('gateway down', 502)]);

        $session = $this->rewrite($this->selfCareSession());

        $this->assertSame('failed', $session->ai_status);
        $this->assertNull($session->ai_advice);
        $this->assertSame(self::SOURCE_ADVICE, $session->advice_given);
    }

    public function test_the_prompt_carries_neither_free_text_nor_profile_data(): void
    {
        \App\Models\UserHealthProfile::create([
            'MaKH' => $this->user->MaKH,
            'allergies' => ['penicillin'],
            'consented_at' => now(),
        ]);

        $this->fakeProviderReply(implode("\n", [
            'LỜI KHUYÊN:',
            'Anh/chị nghỉ ngơi, uống nước, mặc thoáng, đo nhiệt độ mỗi 4–6 giờ.',
            '',
            'NGƯỠNG CẦN ĐI KHÁM:',
            'Đi khám nếu sốt trên 39 độ hoặc quá 3 ngày.',
        ]));

        $this->rewrite($this->selfCareSession());

        Http::assertSent(function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString('toi bi sot nhe', $body);
            $this->assertStringNotContainsString('penicillin', $body);

            return true;
        });
    }

    public function test_the_result_page_labels_the_rewrite_and_keeps_the_original(): void
    {
        $this->fakeProviderReply(implode("\n", [
            'LỜI KHUYÊN:',
            'Anh/chị nghỉ ngơi, uống nhiều nước, mặc thoáng và đo nhiệt độ mỗi 4–6 giờ nhé.',
            '',
            'NGƯỠNG CẦN ĐI KHÁM:',
            'Đi khám ngay nếu sốt trên 39 độ hoặc quá 3 ngày ạ.',
        ]));

        $session = $this->rewrite($this->selfCareSession());

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.ai_badge'))
            ->assertSee(__('messages.health.ai_original_toggle'))
            // Both versions are on the page: the original is one click away and prints.
            ->assertSee('Đo lại nhiệt độ mỗi 4–6 giờ.', false)
            ->assertSee('đo nhiệt độ mỗi 4–6 giờ nhé', false);
    }

    public function test_the_audit_row_records_status_but_no_advice_text(): void
    {
        $this->fakeProviderReply(implode("\n", [
            'LỜI KHUYÊN:',
            'Anh/chị nghỉ ngơi, uống nước, mặc thoáng, đo nhiệt độ mỗi 4–6 giờ.',
            '',
            'NGƯỠNG CẦN ĐI KHÁM:',
            'Đi khám nếu sốt trên 39 độ hoặc quá 3 ngày.',
        ]));

        $this->rewrite($this->selfCareSession());

        $log = AuditLog::where('action', 'triage_ai_advice')->firstOrFail();
        $encoded = json_encode($log->new_values, JSON_UNESCAPED_UNICODE);

        $this->assertSame('ok', $log->new_values['ai_status']);
        $this->assertStringNotContainsString('nghỉ ngơi', $encoded);
        $this->assertStringNotContainsString('toi bi sot nhe', $encoded);
    }
}
