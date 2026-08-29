<?php

namespace Tests\Feature;

use App\Models\TriageSession;
use App\Models\User;
use App\Services\MedicalTriageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The scope notice, and the once-a-day popup that emphasises it.
 *
 * The invariant that matters: the popup is emphasis, never the only place the medical
 * scope text lives. Dismissing it silences the popup for the rest of the calendar day
 * and changes nothing else — the compact `role="note"` block keeps the full body on
 * both triage pages and on the printout.
 *
 * The acknowledgement is per-user on the server, not a cookie or localStorage, so a
 * shared in-store tablet cannot suppress the notice for the next person's login.
 */
class TriageDisclaimerPopupTest extends TestCase
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
            'collected_data' => ['raw_symptom_text' => 'dau dau'],
        ], $attributes));
    }

    public function test_the_popup_shows_on_the_first_visit_of_the_day(): void
    {
        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertOk()
            // role="dialog", not data-disclaimer-popup: the latter also appears inside the
            // inline script's querySelector, so it survives deleting the markup.
            ->assertSee('role="dialog"', false)
            ->assertSee('data-disclaimer-panel', false)
            ->assertSee(__('messages.health.disclaimer_ack'))
            // A real dialog: assistive tech is told the rest of the page is unavailable,
            // and the JS layer backs that with `inert`.
            ->assertSee('aria-modal="true"', false);
    }

    public function test_an_explicit_disclaimer_zero_suppresses_it_for_that_url_only(): void
    {
        // Escape and a scrim click mean "not now", so they must not write the daily flag —
        // but the result page reloads itself when a polled AI job lands. The close handler
        // records the refusal in the URL so those reloads do not re-steal focus.
        $this->actingAs($this->user)
            ->get(route('health.check', ['disclaimer' => 0]))
            ->assertOk()
            ->assertDontSee('data-disclaimer-popup', false);

        // Nothing was persisted: a fresh visit asks again.
        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertOk()
            ->assertSee('role="dialog"', false);

        $this->assertFalse(Cache::has(
            "triage:disclaimer_ack:{$this->user->MaKH}:" . today()->toDateString()
        ));
    }

    public function test_the_review_link_is_present_even_while_the_modal_is_open(): void
    {
        // Escape closes the modal client-side without a reload, so a notice with no way
        // back to the full text would be a dead end for the rest of the day.
        //
        // Anchored on the href, not the label: "Xem lại" also appears in a CSS comment in
        // the styles partial, which @stack('styles') puts in <head>, so asserting the
        // label alone passes with the link deleted.
        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertOk()
            ->assertSee('data-disclaimer-popup', false)
            ->assertSee('disclaimer=1', false)
            ->assertSee('class="health-disclaimer__body', false);
    }

    public function test_the_notice_keeps_the_sr_only_body_class_that_print_restores(): void
    {
        // The class carries both the clip-path that compacts the notice on screen and the
        // @media print rule that brings the full text back for the clinic copy. Without an
        // anchor on it, the notice could regress to the always-visible banner — or lose the
        // printout — with the suite green.
        $session = $this->completedSession();

        foreach ([route('health.check'), route('health.result', $session->MaSL)] as $url) {
            $this->actingAs($this->user)
                ->get($url)
                ->assertOk()
                ->assertSee('class="health-disclaimer__body', false);
        }

        $styles = view('health.partials.styles')->render();
        $this->assertStringContainsString('clip-path: inset(50%)', $styles);
        $this->assertStringContainsString('.health-disclaimer__body {', $styles);
    }

    public function test_the_popup_does_not_return_after_it_is_dismissed(): void
    {
        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertSee('data-disclaimer-popup', false);

        $this->actingAs($this->user)
            ->post(route('health.disclaimer.ack'))
            ->assertRedirect(route('health.check'));

        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertOk()
            ->assertDontSee('data-disclaimer-popup', false);
    }

    public function test_the_scope_notice_survives_dismissal_in_full_on_both_pages(): void
    {
        $session = $this->completedSession();

        $this->actingAs($this->user)->post(route('health.disclaimer.ack'));

        foreach ([route('health.check'), route('health.result', $session->MaSL)] as $url) {
            $this->actingAs($this->user)
                ->get($url)
                ->assertOk()
                ->assertDontSee('data-disclaimer-popup', false)
                ->assertSee('role="note"', false)
                ->assertSee(__('messages.health.disclaimer_title'))
                // The body is off-screen for sighted users but still in the DOM: screen
                // readers hear it, and @media print brings it back for the clinic copy.
                ->assertSee(__('messages.health.disclaimer_body'))
                // The href, not the label: "Xem lại" also appears in a CSS comment in the
                // styles partial that @stack('styles') puts in <head>.
                ->assertSee('disclaimer=1', false);
        }
    }

    public function test_the_review_link_reopens_the_popup_without_clearing_the_daily_flag(): void
    {
        $this->actingAs($this->user)->post(route('health.disclaimer.ack'));

        // Reopening is a read: the popup comes back for this request only.
        $this->actingAs($this->user)
            ->get(route('health.check', ['disclaimer' => 1]))
            ->assertOk()
            ->assertSee('data-disclaimer-popup', false);

        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertOk()
            ->assertDontSee('data-disclaimer-popup', false);
    }

    public function test_the_review_link_also_reopens_it_on_the_result_page(): void
    {
        $session = $this->completedSession();

        $this->actingAs($this->user)->post(route('health.disclaimer.ack'));

        $this->actingAs($this->user)
            ->get(route('health.result', ['MaSL' => $session->MaSL, 'disclaimer' => 1]))
            ->assertOk()
            ->assertSee('data-disclaimer-popup', false);
    }

    public function test_an_emergency_result_ignores_the_review_link_too(): void
    {
        // The tel:115 button is the only action on that page. Not even an explicit
        // request may put a dismissible sheet over it.
        $session = $this->completedSession([
            'outcome' => 'emergency',
            'advice_given' => 'Anh/chị gọi ngay 115.',
        ]);

        $this->actingAs($this->user)
            ->get(route('health.result', ['MaSL' => $session->MaSL, 'disclaimer' => 1]))
            ->assertOk()
            ->assertDontSee('data-disclaimer-popup', false);
    }

    public function test_the_popup_returns_the_next_day(): void
    {
        $this->actingAs($this->user)->post(route('health.disclaimer.ack'));

        // The calendar day is in the cache key, so tomorrow is a different key regardless
        // of how much of the TTL is left.
        $this->travel(1)->day();

        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertOk()
            ->assertSee('data-disclaimer-popup', false);

        $this->travelBack();
    }

    public function test_the_result_page_wires_the_session_id_into_the_dismiss_form(): void
    {
        // The producer of the MaSL field is the @include's argument on the result page.
        // POSTing MaSL directly leaves that wiring untested, so render it first and post
        // exactly what the page emits.
        $session = $this->completedSession();

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee('name="MaSL" value="' . $session->MaSL . '"', false);

        // And the check page has no session id to wire, so it must not invent one.
        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertOk()
            ->assertDontSee('name="MaSL"', false);
    }

    public function test_dismissing_from_the_result_page_returns_to_it(): void
    {
        $session = $this->completedSession();

        $this->actingAs($this->user)
            ->post(route('health.disclaimer.ack'), ['MaSL' => $session->MaSL])
            ->assertRedirect(route('health.result', $session->MaSL));
    }

    public function test_a_junk_session_id_is_rejected_rather_than_producing_a_broken_url(): void
    {
        // 0 and negatives pass a bare `integer` rule and generate URLs that only 404 on
        // arrival, so min:1 rejects them at the door instead.
        foreach ([0, -5] as $junk) {
            $this->actingAs($this->user)
                ->post(route('health.disclaimer.ack'), ['MaSL' => $junk])
                ->assertSessionHasErrors('MaSL');
        }

        // Rejected input must not have silenced the popup either.
        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertOk()
            ->assertSee('data-disclaimer-popup', false);
    }

    public function test_an_empty_session_id_falls_back_to_the_wizard(): void
    {
        // ConvertEmptyStringsToNull turns '' into null before validation, so a stray
        // empty hidden input takes the "no MaSL" branch rather than reaching route(),
        // which would throw UrlGenerationException and 500.
        $this->actingAs($this->user)
            ->post(route('health.disclaimer.ack'), ['MaSL' => ''])
            ->assertRedirect(route('health.check'));
    }

    public function test_another_users_session_id_cannot_be_used_to_probe(): void
    {
        // The redirect is built from a named route and an integer, so there is no
        // open-redirect surface; result() then 404s because it scopes by MaKH.
        $other = User::factory()->create(['role' => 'User', 'status' => 'active']);
        $theirs = TriageSession::create([
            'MaKH' => $other->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'self_care',
            'advice_given' => 'x',
            'collected_data' => ['raw_symptom_text' => 'x'],
        ]);

        $this->actingAs($this->user)
            ->post(route('health.disclaimer.ack'), ['MaSL' => $theirs->MaSL])
            ->assertRedirect(route('health.result', $theirs->MaSL));

        $this->actingAs($this->user)
            ->get(route('health.result', $theirs->MaSL))
            ->assertNotFound();
    }

    public function test_an_emergency_result_is_never_covered_by_the_popup(): void
    {
        // The tel:115 button is the only action on that page; a dismissible sheet must
        // not share the viewport with it. The notice itself still renders — and because
        // there is no popup to reopen, it renders inline rather than offering a "Xem lại"
        // link to nothing.
        $session = $this->completedSession([
            'outcome' => 'emergency',
            'advice_given' => 'Anh/chị gọi ngay 115.',
        ]);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertDontSee('data-disclaimer-popup', false)
            ->assertSee(__('messages.health.disclaimer_body'))
            ->assertDontSee('disclaimer=1', false)
            ->assertDontSee('class="health-disclaimer__body', false);
    }

    public function test_one_users_acknowledgement_does_not_suppress_it_for_another(): void
    {
        // The shared in-store tablet case, and the assertion a cookie or localStorage
        // design cannot pass.
        $other = User::factory()->create(['role' => 'User', 'status' => 'active']);

        $this->actingAs($this->user)->post(route('health.disclaimer.ack'));

        $this->actingAs($other)
            ->get(route('health.check'))
            ->assertOk()
            ->assertSee('data-disclaimer-popup', false);
    }

    public function test_the_acknowledgement_is_keyed_per_user_and_per_day(): void
    {
        $this->actingAs($this->user)->post(route('health.disclaimer.ack'));

        $this->assertTrue(Cache::has(
            "triage:disclaimer_ack:{$this->user->MaKH}:" . today()->toDateString()
        ));
    }

    public function test_the_ajax_stage_swap_never_carries_the_popup(): void
    {
        // The non-nagging invariant the controller's own comment claims: the popup is
        // built only in index()'s terminal return, so no AJAX step swap can re-render it
        // mid-wizard and re-steal focus. The swapped payload is HTML for [data-triage-stage].
        $this->actingAs($this->user)->get(route('health.check'));
        $session = TriageSession::where('MaKH', $this->user->MaKH)->inProgress()->firstOrFail();

        $response = $this->actingAs($this->user)
            ->postJson(route('health.answer'), [
                'triage_session_id' => $session->MaSL,
                'step' => 1,
                'answer' => 'đau đầu',
            ])
            ->assertOk();

        $stage = (string) ($response->json('stage') ?? '');
        $this->assertNotSame('', $stage);
        $this->assertStringNotContainsString('data-disclaimer-popup', $stage);
        $this->assertStringNotContainsString('health-scope-pop', $stage);

        // The JSON disclaimer keys are a separate, older contract; nothing in the triage
        // client reads them, but the chatbot-shaped payload is asserted elsewhere and
        // must not have been disturbed.
        $response->assertJsonPath('show_disclaimer', true)
            ->assertJsonPath('disclaimer', MedicalTriageService::DISCLAIMER);
    }
}
