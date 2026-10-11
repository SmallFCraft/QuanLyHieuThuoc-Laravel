<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Str;
use App\Models\TriageSession;
use App\Models\User;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The result page's shape, not its content: the visitor answered five questions and
 * the page used to make them scroll past an AI essay to find out how the conclusion
 * was reached. These tests pin the restructure — a journey rail for the answers, a
 * tab hub for the prose — and the two rules that must survive it: an emergency page
 * hides nothing behind a control, and a browser without JavaScript still reads the
 * whole page.
 */
class HealthCheckResultLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Category::create(['name' => 'Thuốc giảm đau - Hạ sốt', 'description' => 'Giảm đau, hạ sốt']);
        $this->seed(MedicalKnowledgeSeeder::class);

        $this->user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
            'birth_date' => now()->subYears(34)->toDateString(),
        ]);
    }

    protected function completedSession(array $attributes = []): TriageSession
    {
        return TriageSession::create(array_merge([
            'MaKH' => $this->user->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'self_care',
            'advice_given' => 'Nghỉ ngơi, uống đủ nước.',
            'safety_net' => 'Đi khám nếu sốt cao.',
            'collected_data' => [
                'raw_symptom_text' => 'Đau đầu',
                'duration_days' => 1,
                'medications_taken' => 'Chưa dùng gì',
                'history' => 'Không có',
                'other_symptoms' => 'Không có thêm',
            ],
        ], $attributes));
    }

    public function test_the_journey_rail_shows_every_answer_the_visitor_gave(): void
    {
        $session = $this->completedSession();

        $response = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk();

        $response->assertSee('health-journey', false);
        $response->assertSee(__('messages.health.journey_title'));
        $response->assertSee('Đau đầu');
        $response->assertSee('Chưa dùng gì');
        $response->assertSee(__('messages.health.outcome_self_care'));
    }

    /**
     * A bare "1" reads as a count of nothing. The collapsed details used to append
     * the unit; the rail has to carry it too or the step says nothing.
     */
    public function test_the_duration_step_carries_its_unit(): void
    {
        $session = $this->completedSession();

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('messages.health.basis_duration_today'), $content);
    }

    public function test_a_multi_day_duration_is_pluralised(): void
    {
        $session = $this->completedSession([
            'collected_data' => [
                'raw_symptom_text' => 'Đau đầu',
                'duration_days' => 3,
                'medications_taken' => 'Chưa dùng gì',
                'history' => 'Không có',
                'other_symptoms' => 'Không có thêm',
            ],
        ]);

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('messages.health.basis_duration_days', ['days' => 3]), $content);
    }

    public function test_the_rail_names_each_of_the_five_steps(): void
    {
        $session = $this->completedSession();

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        foreach (['step_1', 'step_2', 'step_3', 'step_4', 'step_5'] as $key) {
            $this->assertStringContainsString(__("messages.health.{$key}"), $content);
        }
    }

    /**
     * A path is one-directional, so the rail must not wrap at any width. Two earlier
     * layouts did: one row of six in the 768px shell left 116px a node, and a 3x2 grid
     * cut the line at the row break and restarted it at the left, so the path visibly
     * ran backwards. Multi-column is the bug, not a preference — no width may reintroduce
     * it.
     */
    public function test_the_rail_never_wraps_into_columns(): void
    {
        $session = $this->completedSession();

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        // No `grid-template-columns` on the rail anywhere: neither in the base rule nor
        // inside a media query. One column is the absence of a column definition.
        $this->assertDoesNotMatchRegularExpression(
            '/\.health-journey\s*\{[^}]*grid-template-columns/s',
            $content
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.health-journey\s*\{[^}]*repeat\(\s*[2-9]/s',
            $content
        );
    }

    /**
     * Every node but the last is joined to the next one. `:last-child` — not
     * `:nth-child(3n)` — because the connector must survive a rail with five nodes
     * (the non-emergency case, where step 1 ends the wizard) as well as six.
     */
    public function test_the_rail_connects_every_node_to_the_next(): void
    {
        $session = $this->completedSession();

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('.health-journey__step:not(:last-child)::after', $content);
        $this->assertStringNotContainsString('nth-child(3n)', $content);
        $this->assertStringNotContainsString('nth-child(2n)', $content);
    }

    /**
     * The rail is a sticky sidebar: pinned while the reader scrolls the page it
     * summarises. `position: sticky` only works against a scrolling ancestor, so the
     * thing that makes it fail silently is a shell that is `overflow: hidden` — this
     * pins the property, not the ancestor.
     */
    public function test_the_rail_is_a_sticky_sidebar(): void
    {
        $session = $this->completedSession();

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/\.health-rail\s*\{[^}]*position:\s*sticky/s',
            $content
        );

        // Offset from the fixed navbar, not 0: pinned at 0 the top of the rail sits
        // underneath the header.
        $this->assertMatchesRegularExpression(
            '/\.health-rail\s*\{[^}]*top:\s*(?!0)[^;}]+/s',
            $content
        );

        $this->assertMatchesRegularExpression(
            '/@media\s*\(min-width:[^)]*\)\s*\{[^@]*\.health-shell--rail\s*\{[^}]*display:\s*grid/s',
            $content
        );

        // The page the rail belongs to must opt into the two-column shell.
        $this->assertStringContainsString('health-shell health-shell--rail', $content);
    }

    /**
     * The rail is a direct child of the grid, and everything else is inside a single
     * content element.
     *
     * This is the whole layout, and it was wrong three times: nested inside the content
     * wrapper the rail was placed by `grid-column: 2` along with everything else and
     * ended up under the tabs on the right; and with no wrapper at all every child
     * became its own implicit grid row, so the rail's height stretched row 1 and left a
     * 200px hole under the header. Neither is visible in a passing render — the page
     * looks plausible either way — so both are pinned here structurally.
     */
    public function test_the_rail_and_the_content_are_separate_grid_cells(): void
    {
        $session = $this->completedSession();

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        // Exactly one content wrapper and one rail, and the rail is not inside it.
        $this->assertSame(1, substr_count($content, 'class="health-shell__content"'));
        $this->assertSame(1, substr_count($content, 'class="health-rail"'));

        $shellOpen = strpos($content, 'health-shell health-shell--rail');
        $railOpen = strpos($content, 'class="health-rail"');
        $contentOpen = strpos($content, 'class="health-shell__content"');

        $this->assertNotFalse($shellOpen);
        $this->assertNotFalse($railOpen);
        $this->assertNotFalse($contentOpen);

        // The rail opens before the wrapper, and nothing reopens a wrapper after it, so
        // there is exactly one grid cell for the content.
        $this->assertLessThan($contentOpen, $railOpen, 'The rail must precede the content wrapper.');

        // The hub and the buttons must sit inside the wrapper rather than each falling
        // into a grid cell of its own — which is what put the hub in the rail's column
        // when the wrapper closed too early. Anchored on the elements, not on Blade
        // comments: Blade strips those from the rendered HTML.
        $hubPos = strpos($content, 'class="health-hub mt-4"', $contentOpen);
        $buttonsPos = strpos($content, 'justify-content-center mt-4 health-no-print', $contentOpen);
        $this->assertNotFalse($hubPos);
        $this->assertNotFalse($buttonsPos);

        // The rail's own markup must come after the wrapper opens, or the hub is outside
        // it. (The rail opens before the wrapper; a second rail inside it would mean the
        // wrapper closed in the wrong place.)
        $this->assertSame(
            0,
            substr_count(substr($content, $contentOpen, 20000), 'class="health-rail"'),
            'The rail must not be nested inside the content wrapper.'
        );

        $this->assertGreaterThan($hubPos, $buttonsPos, 'The buttons follow the hub inside the wrapper.');
    }

    /**
     * The 115 action must not sit behind a control, and must not sit inside the rail.
     * The rail is `display:none` below 992px, so a call button in there is invisible on
     * the phone of the patient who needs it most — the exact bug this pins. It renders
     * in the page body, above the disclaimer, exactly once.
     */
    public function test_the_emergency_call_action_renders_outside_the_rail(): void
    {
        $session = $this->completedSession([
            'outcome' => 'emergency',
            'advice_given' => null,
            'safety_net' => null,
            'triggered_red_flags' => [['code' => 'chest_pain', 'name_vi' => 'Đau ngực dữ dội']],
            'collected_data' => ['raw_symptom_text' => 'Đau ngực dữ dội'],
        ]);

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        $railOpen = strpos($content, 'class="health-rail"');
        $railClose = strpos($content, '</aside>', $railOpen);
        $this->assertNotFalse($railOpen);
        $this->assertNotFalse($railClose);

        $rail = substr($content, $railOpen, $railClose - $railOpen);

        // The 115 action is NOT in the rail: the rail is display:none below 992px, so a
        // call button there is invisible on the phone of the patient who needs it most.
        $this->assertStringNotContainsString('tel:115', $rail);

        // Exactly one call action on the page, and it precedes the notice. Anchored on
        // the element, not the string: the class name also appears in the inline block
        // in <head>, which sits earlier in the document than everything.
        $this->assertSame(1, substr_count($content, 'tel:115'));
        $this->assertLessThan(
            strpos($content, 'class="health-disclaimer'),
            strpos($content, 'tel:115'),
        );

        // The red flags still render once, in the emergency banner.
        $this->assertSame(1, substr_count($content, __('messages.health.red_flags_title')));
    }

    /**
     * Below the sidebar breakpoint the rail is removed rather than stacked: a sticky
     * column becomes a long block at the top of the page on a phone, which is the
     * layout the tab hub replaced. The audit tab still carries all five answers, so
     * nothing is lost — but if the rail ever does render there, it must not be hidden
     * from assistive tech while staying visible.
     */
    public function test_the_rail_is_hidden_below_the_sidebar_breakpoint(): void
    {
        $session = $this->completedSession();

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/@media\s*\(max-width:[^)]*\)\s*\{[^@]*\.health-rail\s*\{\s*display:\s*none/s',
            $content
        );
    }

    /**
     * A step the visitor skipped is an answer: the rule engine's caution is partly
     * explained by it. Dropping the node instead would leave the rail claiming four
     * answers where the transcript has five.
     */
    public function test_a_skipped_step_stays_on_the_rail_marked_as_skipped(): void
    {
        $session = $this->completedSession([
            'collected_data' => [
                'symptoms' => null,
                'raw_symptom_text' => null,
                'duration_days' => 1,
                'medications_taken' => null,
                'history' => null,
                'other_symptoms' => null,
                'skipped' => ['medications_taken'],
            ],
        ]);

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        // Anchored on the markup, not the class name: the first `health-journey` in the
        // document is the CSS rule in the head, which would make this assert nothing.
        $rail = substr($content, (int) strpos($content, 'class="health-journey'), 4000);

        $this->assertStringContainsString(__('messages.health.trail_skipped'), $rail);
        $this->assertStringContainsString(__('messages.health.step_3'), $rail);
    }

    public function test_the_rail_never_shows_a_step_the_visitor_was_not_asked(): void
    {
        // Emergency: the wizard stops at step 1, so four of the five answers were
        // never collected. A rail padded to five would invent them.
        $session = $this->completedSession([
            'outcome' => 'emergency',
            'collected_data' => ['raw_symptom_text' => 'Đau ngực dữ dội'],
        ]);

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        $rail = substr($content, (int) strpos($content, 'class="health-journey'), 6000);

        $this->assertStringContainsString('Đau ngực dữ dội', $rail);
        $this->assertStringNotContainsString(__('messages.health.step_4'), $rail);
    }

    public function test_the_page_groups_its_sections_into_tabs(): void
    {
        $session = $this->completedSession();

        $response = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk();

        $response->assertSee('role="tablist"', false);
        $response->assertSee('tab-pane', false);
        $response->assertSee('hub-briefing', false);
        $response->assertSee('hub-audit', false);

        // The audit trail is still on the page, just inside its own panel.
        $response->assertSee(__('messages.health.basis_title'));
    }

    public function test_an_emergency_page_never_hides_content_behind_a_tab(): void
    {
        $session = $this->completedSession([
            'outcome' => 'emergency',
            'advice_given' => null,
            'safety_net' => null,
            'collected_data' => ['raw_symptom_text' => 'Đau ngực dữ dội'],
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk();

        // A red-flag page shows everything at once: the 115 action, the red flags
        // and the audit trail all in view with no control between them.
        $response->assertSee('tel:115', false);
        $response->assertSee(__('messages.health.outcome_emergency'));
        $response->assertDontSee('role="tablist"', false);
    }

    /**
     * A red-flag referral has one thing the patient must read first: what to do now.
     * The AI briefing is still being written at that moment, so opening it first
     * shows a loading skeleton above the advice. Advice tab opens by default; the
     * briefing job still runs in the background.
     */
    public function test_a_red_flag_referral_opens_the_care_tab_while_the_briefing_is_pending(): void
    {
        $session = $this->completedSession([
            'outcome' => 'refer_doctor',
            'triggered_red_flags' => [['code' => 'pregnancy_symptom', 'name_vi' => 'Đang mang thai', 'severity' => 'urgent']],
            'ai_briefing_status' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk();

        $response->assertSee('class="tab-pane fade show active" id="hub-care"', false);
        $response->assertSee('class="tab-pane fade" id="hub-briefing"', false);
    }

    public function test_the_hub_holds_three_panels(): void
    {
        $session = $this->completedSession();

        $response = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk();

        $response->assertSee('hub-briefing', false);
        $response->assertSee('hub-care', false);
        $response->assertSee('hub-audit', false);
    }

    /**
     * The audit panels used to be collapsed `<details>` at the tail of a long column,
     * where collapsing saved a screen. Inside their own tab they are the content, and
     * two closed chevrons would show a visitor nothing at all.
     */
    public function test_the_audit_tab_shows_its_content_without_a_click(): void
    {
        $session = $this->completedSession();

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        $audit = substr($content, (int) strpos($content, 'id="hub-audit"'), 12000);

        $this->assertStringContainsString(__('messages.health.basis_title'), $audit);
        $this->assertStringContainsString(__('messages.health.answers_title'), $audit);
        $this->assertStringNotContainsString('<details', $audit);
    }

    /**
     * The medicines used to render twice: once in the outcome block, once more under
     * a second label in a products tab. One list, one heading.
     */
    public function test_the_products_appear_once(): void
    {
        $category = Category::first();
        $product = Product::create([
            'MaDM' => $category->MaDM,
            'name' => 'Paracetamol 500mg',
            'ingredients' => 'Paracetamol 500mg',
            'usage' => 'Giảm đau đầu, hạ sốt',
            'price' => 25000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 50,
            'reorder_point' => 5,
            'batch_number' => 'B-ONCE',
        ]);

        $session = $this->completedSession([
            'matched_symptoms' => ['dau_dau'],
            'suggested_products' => [[
                'id' => $product->MaSP,
                'name' => 'Paracetamol 500mg',
                'price' => 25000,
                'stock_quantity' => 50,
                'usage' => 'Uống 1 viên mỗi 6 giờ.',
            ]],
        ]);

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($content, __('messages.health.products_title')));
    }

    public function test_a_browser_without_javascript_sees_every_panel(): void
    {
        $session = $this->completedSession();

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        // Bootstrap hides a `.tab-pane` without `.show active`, so a visitor whose
        // script never runs would see one panel and a tab bar that does nothing.
        // The reading path cannot depend on a click.
        $this->assertMatchesRegularExpression(
            '/html:not\(\.js\)[^{]*\.health-hub__panels[^{]*\{[^}]*display:\s*block/s',
            $content
        );
    }

    /** An emergency result shows the no-products conclusion in its own outcome surface. */
    public function test_the_no_products_notice_is_a_conclusion_not_a_bare_paragraph(): void
    {
        $session = $this->completedSession([
            'outcome' => 'emergency',
            'suggested_products' => [],
        ]);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee('class="health-no-products health-no-products--emergency"', false)
            ->assertSee(__('messages.health.no_products_title'))
            ->assertSee(__('messages.health.no_products'));
    }

    /**
     * On an emergency page the tabs are off, so the three panes stack flat in the page.
     * Nothing separated them: the conclusion card in the care pane and the "Em dựa vào
     * đâu" card in the audit pane met at 0px, while cards inside a pane sit 24px (`mt-4`)
     * apart. The break is a flat-stack gap rule missing. Two things pin it: the hub
     * carries `health-hub--flat`, and that rule moves a pane 24px off its predecessor
     * only when the pane actually holds an element (`:has(*)`), so an empty briefing
     * pane cannot pull a phantom margin under the hub edge.
     */
    public function test_flat_panes_on_an_emergency_page_keep_a_gap_between_them(): void
    {
        $session = $this->completedSession([
            'outcome' => 'emergency',
            'suggested_products' => [],
        ]);

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('health-hub--flat', $content);
        $this->assertMatchesRegularExpression(
            '/\.health-hub--flat \.health-hub__panels > \.tab-pane:has\(\*\) ~ \.tab-pane:has\(\*\)\s*\{[^}]*margin-top:\s*24px/s',
            $content
        );
    }

    /**
     * The flat class is a layout statement, not a severity one: a non-emergency page
     * keeps its tab bar, so it must not carry it. Pinning the negative side stops the
     * selector above from being "fixed" by tagging every page flat.
     */
    public function test_a_tabbed_page_is_not_marked_flat(): void
    {
        // Anchored on the element: the selector for the rule is inline CSS in <head>,
        // and a bare substring check would find it on every page.
        $this->actingAs($this->user)
            ->get(route('health.result', $this->completedSession()->MaSL))
            ->assertOk()
            ->assertDontSee('class="health-hub mt-4 health-hub--flat"', false);
    }

    /**
     * The emergency notice is a two-child strip — the ⓘ, then the text — but the base
     * rule is a `space-between` wrapping row built for the reopenable shape, where the
     * badge+title sit left and "Xem lại" sits right. Inherited, it pushes the icon onto
     * a line of its own: an ⓘ floating above the paragraph with nothing to attach to,
     * measured at 375px as an icon whose top sits 2px above the text block it should
     * share a line with. Both properties have to be overridden — `flex-start` alone
     * still wraps once the text column grows.
     */
    public function test_the_emergency_notice_keeps_its_icon_on_the_first_line(): void
    {
        $session = $this->completedSession([
            'outcome' => 'emergency',
            'suggested_products' => [],
        ]);

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/\.health-disclaimer--emergency\s*\{[^}]*justify-content:\s*flex-start/s',
            $content
        );
        $this->assertMatchesRegularExpression(
            '/\.health-disclaimer--emergency\s*\{[^}]*flex-wrap:\s*nowrap/s',
            $content
        );
    }

    public function test_the_print_block_shows_every_panel_and_drops_the_tab_bar(): void
    {
        $session = $this->completedSession();

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        $printBlock = substr($content, (int) strrpos($content, '@media print'));
        $this->assertStringContainsString('.health-hub__tabbar { display: none !important; }', $printBlock);
        $this->assertMatchesRegularExpression(
            '/\.health-hub__panels\s+\.tab-pane[^{]*\{[^}]*display:\s*block\s*!important/s',
            $printBlock
        );
    }

    /**
     * A finished briefing lands in place. A reload would drop the tab the reader has
     * open and the scroll position they have built up while waiting.
     *
     * The reload stays only on the `stranded` branch, which needs the full page to run
     * the controller's inline fallback; the `done` branch must not reach it.
     *
     * Scoped from `function swapPane()` on purpose: the chatbot widget ships its own
     * poller in the same page and it also tests `state.status === 'done'`.
     */
    public function test_the_poller_swaps_the_briefing_pane_instead_of_reloading(): void
    {
        $session = $this->completedSession(['ai_briefing_status' => null]);
        $this->queuedBriefing($session);

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        $poller = substr($content, (int) strpos($content, 'function swapPane()'));
        $doneAt = strpos($poller, "state.status === 'done'");
        $strandedAt = strpos($poller, 'state.stranded');

        $this->assertNotFalse($doneAt, 'The briefing poller must handle a finished job.');
        $this->assertNotFalse($strandedAt, 'The briefing poller must handle a stranded job.');
        $this->assertLessThan($strandedAt, $doneAt);

        $doneBranch = substr($poller, $doneAt, $strandedAt - $doneAt);

        $this->assertStringContainsString('swapPane()', $doneBranch);
        $this->assertStringNotContainsString('window.location.reload()', $doneBranch);
    }

    /**
     * The endpoint that renders the panel travels with the poll job, so the script
     * does not have to rebuild the route from the id it was given.
     */
    public function test_the_poller_carries_the_briefing_pane_url(): void
    {
        $session = $this->completedSession(['ai_briefing_status' => null]);
        $this->queuedBriefing($session);

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        // The jobs are embedded by @json, which escapes slashes, so this asserts on
        // the decoded payload rather than the raw markup.
        preg_match('/const jobs = (\[.*?\]);/s', $content, $matches);
        $this->assertNotEmpty($matches, 'The result page must embed its poll jobs.');

        $briefing = collect(json_decode($matches[1], true))
            ->firstWhere('banner', 'triage-briefing-pending');

        $this->assertNotNull($briefing, 'The briefing job must be among the poll jobs.');
        $this->assertSame(
            route('health.result.briefing-pane', $session->MaSL),
            $briefing['paneUrl'] ?? null
        );
    }

    /**
     * A queued briefing job, so the page renders a poller for it.
     */
    protected function queuedBriefing(TriageSession $session): AiJob
    {
        return AiJob::create([
            'uuid' => (string) Str::uuid(),
            'MaKH' => $this->user->MaKH,
            'kind' => AiJob::KIND_TRIAGE_BRIEFING,
            'status' => 'queued',
            'payload' => ['MaSL' => $session->MaSL],
        ]);
    }
}
