<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\TriageSession;
use App\Models\User;
use App\Models\UserHealthProfile;
use App\Services\Triage\Ai\TriageAiBriefingService;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The detailed result-page briefing — the one AI seam that writes new content.
 *
 * The invariants under test: it cannot touch `outcome`, `advice_given` or
 * `safety_net`; it can only suggest product ids that exist, are in stock and sit in
 * a category the pharmacy's own triage rules suggest from; its suggestions go
 * through the same drug-safety check as the rule engine's; and an emergency page
 * never gets one at all.
 */
class TriageAiBriefingTest extends TestCase
{
    use RefreshDatabase;

    protected const SOURCE_ADVICE = 'Anh/chị nghỉ ngơi, uống nhiều nước, mặc thoáng.';
    protected const SOURCE_SAFETY = 'Đi khám ngay nếu sốt trên 39 độ không hạ hoặc sốt quá 3 ngày.';

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // The candidate list is derived from `medical_triage_rules.suggest_categories`,
        // so the real seed is what makes this test exercise the real allow-list.
        // Category first: the seeder maps rule categories by name.
        Category::create(['name' => 'Thuốc giảm đau - Hạ sốt', 'description' => 'Giảm đau, hạ sốt']);
        $this->seed(MedicalKnowledgeSeeder::class);

        config()->set('chatbot.ai.triage.briefing', true);

        $this->user = User::factory()->create(['role' => 'User', 'status' => 'active']);
    }

    /**
     * A product in a category the seeded triage rules are allowed to suggest from.
     * The service reads that list rather than a config, so a category no rule points
     * at is never a candidate.
     */
    protected function stockedProduct(string $name = 'Paracetamol 500mg', string $ingredients = 'Paracetamol 500mg'): Product
    {
        return Product::create([
            'MaDM' => Category::first()->MaDM,
            'name' => $name,
            'ingredients' => $ingredients,
            'usage' => 'Giảm đau, hạ sốt. Uống 1-2 viên mỗi 4-6 giờ',
            'price' => 2000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 50,
            'reorder_point' => 5,
            'batch_number' => 'B-' . uniqid(),
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function fakeToolCall(array $arguments): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'ghi_dien_giai',
                            'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE),
                        ],
                    ]],
                ],
            ]],
        ])]);
    }

    protected function triageSession(array $attributes = []): TriageSession
    {
        return TriageSession::create(array_merge([
            'MaKH' => $this->user->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'self_care',
            'advice_given' => self::SOURCE_ADVICE,
            'safety_net' => self::SOURCE_SAFETY,
            'matched_symptoms' => ['fever'],
            'collected_data' => ['raw_symptom_text' => 'toi bi sot nhe'],
        ], $attributes));
    }

    protected function generate(TriageSession $session): TriageSession
    {
        app(TriageAiBriefingService::class)->generate($session);

        return $session->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    protected function fullBriefing(array $overrides = []): array
    {
        return array_merge([
            'why' => 'Em kết luận tự chăm sóc vì anh/chị sốt nhẹ, mới trong hôm nay và không có dấu hiệu nguy hiểm nào.',
            'possible_causes' => ['Nhiễm virus đường hô hấp thông thường, hay gặp khi thời tiết đổi.'],
            'self_care' => ['Uống đủ nước, nghỉ ngơi, mặc thoáng.'],
            'watch_for' => ['Sốt tăng dần hoặc không hạ sau khi hạ nhiệt.'],
            'see_doctor_when' => ['Sốt trên 39 độ không hạ, hoặc sốt quá 3 ngày.'],
            'ask_pharmacist' => ['Em nên dùng thuốc hạ sốt cách nhau bao lâu?'],
        ], $overrides);
    }

    public function test_flag_off_sends_nothing(): void
    {
        config()->set('chatbot.ai.triage.briefing', false);

        $session = $this->generate($this->triageSession());

        Http::assertNothingSent();
        $this->assertNull($session->ai_briefing);
        $this->assertNull($session->ai_briefing_status);
    }

    public function test_an_emergency_outcome_never_gets_a_briefing(): void
    {
        $session = $this->generate($this->triageSession([
            'outcome' => 'emergency',
            'advice_given' => 'Anh/chị gọi ngay 115.',
            'safety_net' => null,
        ]));

        Http::assertNothingSent();
        $this->assertNull($session->ai_briefing);
    }

    public function test_a_full_briefing_is_stored_without_touching_the_conclusion(): void
    {
        $this->fakeToolCall($this->fullBriefing());

        $session = $this->generate($this->triageSession());

        $this->assertSame('ok', $session->ai_briefing_status);
        $this->assertStringContainsString('không có dấu hiệu nguy hiểm', $session->ai_briefing['why']);
        $this->assertCount(1, $session->ai_briefing['watch_for']);

        // The deterministic record is untouched.
        $this->assertSame('self_care', $session->outcome);
        $this->assertSame(self::SOURCE_ADVICE, $session->advice_given);
        $this->assertSame(self::SOURCE_SAFETY, $session->safety_net);
    }

    public function test_markdown_is_stripped_rather_than_rejecting_the_whole_briefing(): void
    {
        // One stray marker must not cost the customer the entire explanation: every
        // display site renders with {{ }} and no markdown renderer.
        $this->fakeToolCall($this->fullBriefing([
            'why' => '## Vì sao\n**Em** kết luận tự chăm sóc vì anh/chị sốt nhẹ.',
            'self_care' => ['- Uống đủ nước và nghỉ ngơi.'],
        ]));

        $session = $this->generate($this->triageSession());

        $this->assertSame('ok', $session->ai_briefing_status);
        $this->assertStringNotContainsString('**', $session->ai_briefing['why']);
        $this->assertStringNotContainsString('##', $session->ai_briefing['why']);
        $this->assertSame('Uống đủ nước và nghỉ ngơi.', $session->ai_briefing['self_care'][0]);
    }

    public function test_why_returned_as_array_or_json_array_is_flattened_into_readable_prose(): void
    {
        // Session 31 & 40 regression: some models (e.g. opencode) return `why` as an array
        // of sentences, which either json_decode leaves as array or gets json-encoded into
        // a raw JSON string like ["Em tóm lại...", "Anh/chị 41 tuổi..."].
        $sentences = [
            'Em tóm lại thông tin anh/chị kể.',
            'Anh/chị 41 tuổi, đau đầu 1 ngày, từng có đợt đau đầu ngày 13/09/2026 mức tự chăm sóc.',
            'Hệ thống khớp nhóm đau đầu, buồn nôn.',
            'Mức hiện tại phù hợp tự chăm sóc tại nhà, theo dõi thêm, chưa thấy dấu hiệu cần đi khám ngay.',
        ];

        // Case A: model returned raw PHP array for why
        $this->fakeToolCall($this->fullBriefing([
            'why' => $sentences,
        ]));

        $sessionA = $this->generate($this->triageSession());
        $this->assertSame('ok', $sessionA->ai_briefing_status);
        $this->assertStringNotContainsString('["', $sessionA->ai_briefing['why']);
        $this->assertStringNotContainsString('"]', $sessionA->ai_briefing['why']);
        $this->assertStringContainsString('Em tóm lại thông tin anh/chị kể.', $sessionA->ai_briefing['why']);
        $this->assertStringContainsString('Mức hiện tại phù hợp tự chăm sóc tại nhà', $sessionA->ai_briefing['why']);

        // Case B: model returned JSON-encoded array string for why
        $this->fakeToolCall($this->fullBriefing([
            'why' => json_encode($sentences, JSON_UNESCAPED_UNICODE),
        ]));

        $sessionB = $this->generate($this->triageSession());
        $this->assertSame('ok', $sessionB->ai_briefing_status);
        $this->assertStringNotContainsString('["', $sessionB->ai_briefing['why']);
        $this->assertStringNotContainsString('"]', $sessionB->ai_briefing['why']);
        $this->assertStringContainsString('Em tóm lại thông tin anh/chị kể.', $sessionB->ai_briefing['why']);
    }

    public function test_a_product_id_outside_the_catalogue_is_dropped(): void
    {
        $product = $this->stockedProduct();

        // 999999 does not exist; the real id does. Only the real one may survive,
        // which is what stops the model naming a medicine the pharmacy has not got.
        $this->fakeToolCall($this->fullBriefing([
            'products' => [
                ['id' => 999999, 'reason' => 'Thuốc này không tồn tại trong kho.'],
                ['id' => $product->MaSP, 'reason' => 'Hạ sốt, giảm đau, phù hợp với sốt nhẹ.'],
            ],
        ]));

        $session = $this->generate($this->triageSession());
        $ids = collect($session->ai_briefing['products'])->pluck('id');

        $this->assertTrue($ids->contains($product->MaSP));
        $this->assertFalse($ids->contains(999999));
    }

    public function test_the_name_and_price_shown_come_from_the_catalogue_not_the_model(): void
    {
        $product = $this->stockedProduct();

        $this->fakeToolCall($this->fullBriefing([
            'products' => [[
                'id' => $product->MaSP,
                'name' => 'Thuốc bịa 999mg',
                'price' => 1,
                'reason' => 'Hạ sốt, giảm đau.',
            ]],
        ]));

        $session = $this->generate($this->triageSession());
        $stored = $session->ai_briefing['products'][0];

        $this->assertSame('Paracetamol 500mg', $stored['name']);
        $this->assertSame(2000, (int) $stored['price']);
    }

    public function test_an_out_of_stock_product_is_never_a_candidate(): void
    {
        $product = $this->stockedProduct();
        $product->forceFill(['stock_quantity' => 0])->save();

        $this->fakeToolCall($this->fullBriefing([
            'products' => [['id' => $product->MaSP, 'reason' => 'Hạ sốt.']],
        ]));

        $session = $this->generate($this->triageSession());

        $this->assertSame([], $session->ai_briefing['products']);
    }

    public function test_a_referral_outcome_gets_prose_but_no_medicine(): void
    {
        // The page exists to send somebody to a doctor; offering something to take
        // first works against it.
        $product = $this->stockedProduct();

        $this->fakeToolCall($this->fullBriefing([
            'products' => [['id' => $product->MaSP, 'reason' => 'Hạ sốt.']],
        ]));

        $session = $this->generate($this->triageSession([
            'outcome' => 'refer_doctor',
            'safety_net' => null,
        ]));

        $this->assertSame('ok', $session->ai_briefing_status);
        $this->assertNotSame('', $session->ai_briefing['why']);
        $this->assertSame([], $session->ai_briefing['products']);
    }

    public function test_a_product_already_suggested_by_the_rules_is_not_repeated(): void
    {
        $product = $this->stockedProduct();

        $this->fakeToolCall($this->fullBriefing([
            'products' => [['id' => $product->MaSP, 'reason' => 'Hạ sốt.']],
        ]));

        $session = $this->generate($this->triageSession([
            'suggested_products' => [['id' => $product->MaSP, 'name' => $product->name]],
        ]));

        $this->assertSame([], $session->ai_briefing['products']);
    }

    public function test_an_empty_tool_call_is_recorded_as_rejected(): void
    {
        $this->fakeToolCall(['why' => '', 'possible_causes' => [], 'self_care' => []]);

        $session = $this->generate($this->triageSession());

        $this->assertSame('rejected', $session->ai_briefing_status);
        $this->assertNull($session->ai_briefing);
        $this->assertSame(self::SOURCE_ADVICE, $session->advice_given);
    }

    public function test_a_why_only_briefing_with_all_sections_empty_is_rejected(): void
    {
        // Session 31 regression: the model filled "why" but left all 5 guidance
        // arrays empty, so the page rendered a lone heading with zero content below.
        $this->fakeToolCall([
            'why' => 'Em thấy anh/chị 41 tuổi, lần này đau đầu mới 1 ngày kèm buồn nôn.',
            'possible_causes' => [],
            'self_care' => [],
            'watch_for' => [],
            'see_doctor_when' => [],
            'ask_pharmacist' => [],
            'products' => [],
        ]);

        $session = $this->generate($this->triageSession());

        $this->assertSame('rejected', $session->ai_briefing_status);
        $this->assertNull($session->ai_briefing);
    }

    public function test_a_briefing_with_why_and_at_least_two_sections_is_accepted(): void
    {
        $this->fakeToolCall([
            'why' => 'Em kết luận tự chăm sóc vì triệu chứng nhẹ.',
            'possible_causes' => ['Căng thẳng công việc.'],
            'self_care' => ['Nghỉ ngơi, uống đủ nước.'],
            'watch_for' => [],
            'see_doctor_when' => [],
            'ask_pharmacist' => [],
        ]);

        $session = $this->generate($this->triageSession());

        $this->assertSame('ok', $session->ai_briefing_status);
        $this->assertNotNull($session->ai_briefing);
        $this->assertSame('Em kết luận tự chăm sóc vì triệu chứng nhẹ.', $session->ai_briefing['why']);
    }

    public function test_a_provider_failure_leaves_the_page_unchanged(): void
    {
        Http::fake(['*' => Http::response('gateway down', 502)]);

        $session = $this->generate($this->triageSession());

        $this->assertSame('failed', $session->ai_briefing_status);
        $this->assertNull($session->ai_briefing);
        $this->assertSame('self_care', $session->outcome);
    }

    /**
     * The candidate block is built from the catalogue's own `usage` column, which
     * averages 2.8KB per product, so the prompt reached 126KB with 40 candidates.
     * Measured 2026-09-15 against the live provider: 126KB took 132s to first byte
     * and 23KB took 16s, against a 90s briefing budget — the briefing could not
     * succeed at the old size.
     *
     * The ceiling is asserted on the body actually sent, so a field that stops being
     * capped fails here rather than only in production.
     */
    public function test_the_candidate_block_sent_to_the_provider_stays_small(): void
    {
        // A usage line far longer than any real one, in the shape the catalogue
        // stores: instructions and warnings run together in one column.
        $product = $this->stockedProduct('Paracetamol 500mg', 'Paracetamol 500mg');
        $product->forceFill(['usage' => str_repeat('Uống 1-2 viên mỗi 4-6 giờ. ', 400)])->save();

        $this->fakeToolCall($this->fullBriefing());

        $this->generate($this->triageSession());

        Http::assertSent(function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            $this->assertLessThan(
                20000,
                strlen($body),
                'A single candidate must not push the prompt back into the slow path.'
            );
            $this->assertStringNotContainsString(
                str_repeat('Uống 1-2 viên mỗi 4-6 giờ. ', 40),
                $body,
                'The usage line must be cut before it is sent, not after.'
            );

            return true;
        });
    }

    /**
     * The cap is on the prompt only: `cleanProducts()` merges the full candidate row
     * into the stored briefing, so the page still renders the catalogue's own text.
     */
    public function test_the_stored_briefing_keeps_the_full_usage_text(): void
    {
        $product = $this->stockedProduct('Paracetamol 500mg', 'Paracetamol 500mg');
        $product->forceFill(['usage' => str_repeat('Uống 1-2 viên mỗi 4-6 giờ. ', 40)])->save();

        $this->fakeToolCall($this->fullBriefing(['products' => [
            ['id' => $product->MaSP, 'reason' => 'Phù hợp với triệu chứng.'],
        ]]));

        $session = $this->generate($this->triageSession());
        $stored = $session->ai_briefing['products'][0] ?? null;

        $this->assertNotNull($stored);
        $this->assertGreaterThan(
            300,
            mb_strlen((string) ($stored['usage'] ?? '')),
            'The stored briefing must keep the catalogue usage text in full.'
        );
    }

    public function test_the_prompt_carries_the_customers_words_but_never_the_profile(): void
    {
        UserHealthProfile::create([
            'MaKH' => $this->user->MaKH,
            'allergies' => ['penicillin'],
            'consented_at' => now(),
        ]);

        $this->fakeToolCall($this->fullBriefing());

        $this->generate($this->triageSession());

        Http::assertSent(function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            // The "why" section has to quote what the customer said, so their words
            // are in scope; the consented profile is not.
            $this->assertStringContainsString('toi bi sot nhe', $body);
            $this->assertStringNotContainsString('penicillin', $body);

            return true;
        });
    }

    public function test_the_result_page_renders_the_briefing_and_labels_it_as_ai(): void
    {
        $this->fakeToolCall($this->fullBriefing());

        $session = $this->generate($this->triageSession());

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.briefing_title'))
            ->assertSee(__('messages.health.ai_badge'))
            ->assertSee(__('messages.health.briefing_why'))
            ->assertSee('Sốt tăng dần hoặc không hạ sau khi hạ nhiệt.', false);
    }

    public function test_a_briefing_product_the_profile_contraindicates_is_flagged_on_the_page(): void
    {
        // The model picks from the catalogue and knows nothing about this customer, so
        // its suggestions need the cart's drug-safety check as much as the rules' do.
        $ibuprofen = $this->stockedProduct('Ibuprofen 400mg', 'Ibuprofen 400mg');

        \App\Models\DrugContraindication::create([
            'ingredient' => 'ibuprofen',
            'condition_type' => 'pregnancy',
            'severity' => 'absolute',
            'warning_vi' => 'Ibuprofen không dùng khi mang thai.',
            'is_active' => true,
        ]);

        UserHealthProfile::create([
            'MaKH' => $this->user->MaKH,
            'is_pregnant' => true,
            'consented_at' => now(),
        ]);

        $this->fakeToolCall($this->fullBriefing([
            'products' => [['id' => $ibuprofen->MaSP, 'reason' => 'Giảm đau, kháng viêm.']],
        ]));

        $session = $this->generate($this->triageSession());

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.product_has_warning'));
    }

    public function test_opening_the_result_page_queues_a_briefing_that_was_never_generated(): void
    {
        // Sessions finalised before this feature existed, and sessions whose dispatch
        // hit a dead queue, have no briefing and no job. The first view must queue one
        // rather than leaving the page permanently thin.
        $this->fakeToolCall($this->fullBriefing());

        $session = $this->triageSession();

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.briefing_title'));

        $this->assertSame('ok', $session->refresh()->ai_briefing_status);
        $this->assertSame(1, AiJob::where('kind', AiJob::KIND_TRIAGE_BRIEFING)->count());
    }

    public function test_a_failed_briefing_is_not_retried_on_every_reload(): void
    {
        // One dead attempt is enough evidence. Retrying per page view would hammer the
        // provider for as long as the customer keeps the tab open.
        Http::fake(['*' => Http::response('gateway down', 502)]);

        $session = $this->triageSession();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->user)->get(route('health.result', $session->MaSL))->assertOk();
        }

        $this->assertSame('failed', $session->refresh()->ai_briefing_status);
        $this->assertSame(1, AiJob::where('kind', AiJob::KIND_TRIAGE_BRIEFING)->count());
    }

    public function test_the_page_shows_the_waiting_state_while_a_worker_is_writing(): void
    {
        $session = $this->triageSession();

        AiJob::create([
            'uuid' => (string) Str::uuid(),
            'MaKH' => $this->user->MaKH,
            'kind' => AiJob::KIND_TRIAGE_BRIEFING,
            'status' => 'running',
            'payload' => ['MaSL' => $session->MaSL],
        ]);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.briefing_pending'));

        // The existing job is polled, not duplicated.
        $this->assertSame(1, AiJob::where('kind', AiJob::KIND_TRIAGE_BRIEFING)->count());
    }

    public function test_the_audit_row_records_status_but_no_briefing_text(): void
    {
        $this->fakeToolCall($this->fullBriefing());

        $this->generate($this->triageSession());

        $log = AuditLog::where('action', 'triage_ai_briefing')->firstOrFail();
        $encoded = json_encode($log->new_values, JSON_UNESCAPED_UNICODE);

        $this->assertSame('ok', $log->new_values['ai_briefing_status']);
        $this->assertStringNotContainsString('dấu hiệu nguy hiểm', $encoded);
        $this->assertStringNotContainsString('toi bi sot nhe', $encoded);
    }
}
