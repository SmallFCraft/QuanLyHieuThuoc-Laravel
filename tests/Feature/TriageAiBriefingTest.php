<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\NhatKyKiemToan;
use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use App\Models\HoSoSucKhoe;
use App\Services\Triage\Ai\TriageAiBriefingService;
use Database\Seeders\TriThucYTeSeeder;
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

    protected NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // The candidate list is derived from `medical_triage_rules.suggest_categories`,
        // so the real seed is what makes this test exercise the real allow-list.
        // Category first: the seeder maps rule categories by name.
        DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt', 'mo_ta' => 'Giảm đau, hạ sốt']);
        $this->seed(TriThucYTeSeeder::class);

        config()->set('chatbot.ai.triage.briefing', true);

        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
    }

    /**
     * A product in a category the seeded triage rules are allowed to suggest from.
     * The service reads that list rather than a config, so a category no rule points
     * at is never a candidate.
     */
    protected function stockedProduct(string $name = 'Paracetamol 500mg', string $ingredients = 'Paracetamol 500mg'): SanPham
    {
        return SanPham::create(['ma_dm' => DanhMuc::first()->ma_dm,
            'ten' => $name,
            'thanh_phan' => $ingredients,
            'cach_dung' => 'Giảm đau, hạ sốt. Uống 1-2 viên mỗi 4-6 giờ',
            'gia' => 2000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 50,
            'muc_canh_bao' => 5,
            'so_lo' => 'B-' . uniqid(),
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

    protected function triageSession(array $attributes = []): PhienSangLoc
    {
        return PhienSangLoc::create(array_merge([
            'ma_nd' => $this->nguoiDung->ma_nd,
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

    protected function generate(PhienSangLoc $phien): PhienSangLoc
    {
        app(TriageAiBriefingService::class)->generate($phien);

        return $phien->refresh();
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

        $phien = $this->generate($this->triageSession());

        Http::assertNothingSent();
        $this->assertNull($phien->ai_briefing);
        $this->assertNull($phien->ai_briefing_status);
    }

    public function test_an_emergency_outcome_never_gets_a_briefing(): void
    {
        $phien = $this->generate($this->triageSession([
            'outcome' => 'emergency',
            'advice_given' => 'Anh/chị gọi ngay 115.',
            'safety_net' => null,
        ]));

        Http::assertNothingSent();
        $this->assertNull($phien->ai_briefing);
    }

    /**
     * Only an emergency is excluded. A referral still gets its briefing written in
     * the background — the result page opens on the advice tab while this runs, so
     * dropping the briefing would silently remove content from the page.
     */
    public function test_a_referral_outcome_still_gets_its_briefing(): void
    {
        $this->fakeToolCall($this->fullBriefing());

        $phien = $this->generate($this->triageSession([
            'outcome' => 'refer_doctor',
            'advice_given' => 'Anh/chị nên đi khám trong hôm nay.',
        ]));

        Http::assertSentCount(1);
        $this->assertNotNull($phien->ai_briefing);
        $this->assertSame('ok', $phien->ai_briefing_status);
    }

    public function test_a_full_briefing_is_stored_without_touching_the_conclusion(): void
    {
        $this->fakeToolCall($this->fullBriefing());

        $phien = $this->generate($this->triageSession());

        $this->assertSame('ok', $phien->ai_briefing_status);
        $this->assertStringContainsString('không có dấu hiệu nguy hiểm', $phien->ai_briefing['why']);
        $this->assertCount(1, $phien->ai_briefing['watch_for']);

        // The deterministic record is untouched.
        $this->assertSame('self_care', $phien->outcome);
        $this->assertSame(self::SOURCE_ADVICE, $phien->advice_given);
        $this->assertSame(self::SOURCE_SAFETY, $phien->safety_net);
    }

    public function test_markdown_is_stripped_rather_than_rejecting_the_whole_briefing(): void
    {
        // One stray marker must not cost the customer the entire explanation: every
        // display site renders with {{ }} and no markdown renderer.
        $this->fakeToolCall($this->fullBriefing([
            'why' => '## Vì sao\n**Em** kết luận tự chăm sóc vì anh/chị sốt nhẹ.',
            'self_care' => ['- Uống đủ nước và nghỉ ngơi.'],
        ]));

        $phien = $this->generate($this->triageSession());

        $this->assertSame('ok', $phien->ai_briefing_status);
        $this->assertStringNotContainsString('**', $phien->ai_briefing['why']);
        $this->assertStringNotContainsString('##', $phien->ai_briefing['why']);
        $this->assertSame('Uống đủ nước và nghỉ ngơi.', $phien->ai_briefing['self_care'][0]);
    }

    public function test_tool_call_markup_leaked_into_a_field_is_stripped(): void
    {
        // Session 64 (opencode): the model prefixed `why` with the tool-call
        // argument tag (`<parameter>why>...`) instead of emitting clean prose.
        // Transport noise from the provider, not content — it must not reach
        // the customer's screen. Closing tags ride the same wire.
        $sanPham = $this->stockedProduct('Paracetamol 500mg', 'Paracetamol 500mg');

        $this->fakeToolCall($this->fullBriefing([
            'why' => '<parameter>why>Em ghi lại triệu chứng: đau đầu ngày thứ nhất, mức độ bình thường.',
            'watch_for' => ['<parameter name="watch_for">Sốt cao hoặc nôn nhiều.'],
            'products' => [
                ['id' => $sanPham->ma_sp, 'reason' => '<parameter name="reason">Hạ sốt tại nhà, an toàn cho ca này.</parameter>'],
            ],
        ]));

        $phien = $this->generate($this->triageSession());

        $this->assertSame('ok', $phien->ai_briefing_status);
        $this->assertStringNotContainsString('<parameter', $phien->ai_briefing['why']);
        $this->assertStringNotContainsString('why>', $phien->ai_briefing['why']);
        $this->assertStringContainsString('đau đầu ngày thứ nhất', $phien->ai_briefing['why']);
        $this->assertStringNotContainsString('<parameter', $phien->ai_briefing['watch_for'][0]);

        $reason = (string) ($phien->ai_briefing['products'][0]['reason'] ?? '');
        $this->assertStringContainsString('Hạ sốt tại nhà', $reason);
        $this->assertStringNotContainsString('<parameter', $reason);
        $this->assertStringNotContainsString('</parameter>', $reason);
    }

    public function test_tool_call_markup_mid_text_is_stripped(): void
    {
        $this->fakeToolCall($this->fullBriefing([
            'why' => "Đoạn một bình thường.\n<parameter>why>Đoạn hai cần lưu ý.",
        ]));

        $phien = $this->generate($this->triageSession());

        $this->assertSame('ok', $phien->ai_briefing_status);
        $this->assertStringNotContainsString('why>', $phien->ai_briefing['why']);
        $this->assertStringNotContainsString('<parameter', $phien->ai_briefing['why']);
        $this->assertStringContainsString('Đoạn hai cần lưu ý.', $phien->ai_briefing['why']);
    }

    public function test_legitimate_prose_starting_with_a_word_and_greater_than_survives(): void
    {
        // Clinical prose like "pH>7 cần theo dõi" or "dose>2 viên" must not lose its
        // leading token just because it matches `word>`. The orphan strip is only for
        // the residue of a stripped tool-argument tag (`why>`, `watch_for>`).
        $this->fakeToolCall($this->fullBriefing([
            'why' => "pH>7 là ngưỡng kiềm.\ndose>2 viên mỗi ngày là quá liều.",
        ]));

        $phien = $this->generate($this->triageSession());

        $this->assertSame('ok', $phien->ai_briefing_status);
        $this->assertStringContainsString('pH>7 là ngưỡng kiềm.', $phien->ai_briefing['why']);
        $this->assertStringContainsString('dose>2 viên mỗi ngày', $phien->ai_briefing['why']);
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
        $sanPham = $this->stockedProduct();

        // 999999 does not exist; the real id does. Only the real one may survive,
        // which is what stops the model naming a medicine the pharmacy has not got.
        $this->fakeToolCall($this->fullBriefing([
            'products' => [
                ['id' => 999999, 'reason' => 'Thuốc này không tồn tại trong kho.'],
                ['id' => $sanPham->ma_sp, 'reason' => 'Hạ sốt, giảm đau, phù hợp với sốt nhẹ.'],
            ],
        ]));

        $phien = $this->generate($this->triageSession());
        $ids = collect($phien->ai_briefing['products'])->pluck('id');

        $this->assertTrue($ids->contains($sanPham->ma_sp));
        $this->assertFalse($ids->contains(999999));
    }

    public function test_the_name_and_price_shown_come_from_the_catalogue_not_the_model(): void
    {
        $sanPham = $this->stockedProduct();

        $this->fakeToolCall($this->fullBriefing([
            'products' => [[
                'id' => $sanPham->ma_sp,
                'name' => 'Thuốc bịa 999mg',
                'price' => 1,
                'reason' => 'Hạ sốt, giảm đau.',
            ]],
        ]));

        $phien = $this->generate($this->triageSession());
        $stored = $phien->ai_briefing['products'][0];

        $this->assertSame('Paracetamol 500mg', $stored['name']);
        $this->assertSame(2000, (int) $stored['price']);
    }

    public function test_an_out_of_stock_product_is_never_a_candidate(): void
    {
        $sanPham = $this->stockedProduct();
        $sanPham->forceFill(['so_luong_ton' => 0])->save();

        $this->fakeToolCall($this->fullBriefing([
            'products' => [['id' => $sanPham->ma_sp, 'reason' => 'Hạ sốt.']],
        ]));

        $phien = $this->generate($this->triageSession());

        $this->assertSame([], $phien->ai_briefing['products']);
    }

    public function test_a_referral_outcome_gets_prose_but_no_medicine(): void
    {
        // The page exists to send somebody to a doctor; offering something to take
        // first works against it.
        $sanPham = $this->stockedProduct();

        $this->fakeToolCall($this->fullBriefing([
            'products' => [['id' => $sanPham->ma_sp, 'reason' => 'Hạ sốt.']],
        ]));

        $phien = $this->generate($this->triageSession([
            'outcome' => 'refer_doctor',
            'safety_net' => null,
        ]));

        $this->assertSame('ok', $phien->ai_briefing_status);
        $this->assertNotSame('', $phien->ai_briefing['why']);
        $this->assertSame([], $phien->ai_briefing['products']);
    }

    public function test_a_product_already_suggested_by_the_rules_is_not_repeated(): void
    {
        $sanPham = $this->stockedProduct();

        $this->fakeToolCall($this->fullBriefing([
            'products' => [['id' => $sanPham->ma_sp, 'reason' => 'Hạ sốt.']],
        ]));

        $phien = $this->generate($this->triageSession([
            'suggested_products' => [['id' => $sanPham->ma_sp, 'name' => $sanPham->ten]],
        ]));

        $this->assertSame([], $phien->ai_briefing['products']);
    }

    public function test_an_empty_tool_call_is_recorded_as_rejected(): void
    {
        $this->fakeToolCall(['why' => '', 'possible_causes' => [], 'self_care' => []]);

        $phien = $this->generate($this->triageSession());

        $this->assertSame('rejected', $phien->ai_briefing_status);
        $this->assertNull($phien->ai_briefing);
        $this->assertSame(self::SOURCE_ADVICE, $phien->advice_given);
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

        $phien = $this->generate($this->triageSession());

        $this->assertSame('rejected', $phien->ai_briefing_status);
        $this->assertNull($phien->ai_briefing);
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

        $phien = $this->generate($this->triageSession());

        $this->assertSame('ok', $phien->ai_briefing_status);
        $this->assertNotNull($phien->ai_briefing);
        $this->assertSame('Em kết luận tự chăm sóc vì triệu chứng nhẹ.', $phien->ai_briefing['why']);
    }

    public function test_a_provider_failure_leaves_the_page_unchanged(): void
    {
        Http::fake(['*' => Http::response('gateway down', 502)]);

        $phien = $this->generate($this->triageSession());

        $this->assertSame('failed', $phien->ai_briefing_status);
        $this->assertNull($phien->ai_briefing);
        $this->assertSame('self_care', $phien->outcome);
    }

    /**
     * The candidate block is built from the catalogue's own `cach_dung` column, which
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
        $sanPham = $this->stockedProduct('Paracetamol 500mg', 'Paracetamol 500mg');
        $sanPham->forceFill(['cach_dung' => str_repeat('Uống 1-2 viên mỗi 4-6 giờ. ', 400)])->save();

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
        $sanPham = $this->stockedProduct('Paracetamol 500mg', 'Paracetamol 500mg');
        $sanPham->forceFill(['cach_dung' => str_repeat('Uống 1-2 viên mỗi 4-6 giờ. ', 40)])->save();

        $this->fakeToolCall($this->fullBriefing(['products' => [
            ['id' => $sanPham->ma_sp, 'reason' => 'Phù hợp với triệu chứng.'],
        ]]));

        $phien = $this->generate($this->triageSession());
        $stored = $phien->ai_briefing['products'][0] ?? null;

        $this->assertNotNull($stored);
        $this->assertGreaterThan(
            300,
            mb_strlen((string) ($stored['usage'] ?? '')),
            'The stored briefing must keep the catalogue usage text in full.'
        );
    }

    public function test_the_prompt_carries_the_customers_words_but_never_the_profile(): void
    {
        HoSoSucKhoe::create(['ma_nd' => $this->nguoiDung->ma_nd,
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

        $phien = $this->generate($this->triageSession());

        $this->actingAs($this->nguoiDung)
            ->get(route('health.result', $phien->ma_sl))
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

        \App\Models\ChungChiDinhThuoc::create([
            'ingredient' => 'ibuprofen',
            'condition_type' => 'pregnancy',
            'severity' => 'absolute',
            'warning_vi' => 'Ibuprofen không dùng khi mang thai.',
            'is_active' => true,
        ]);

        HoSoSucKhoe::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'is_pregnant' => true,
            'consented_at' => now(),
        ]);

        $this->fakeToolCall($this->fullBriefing([
            'products' => [['id' => $ibuprofen->ma_sp, 'reason' => 'Giảm đau, kháng viêm.']],
        ]));

        $phien = $this->generate($this->triageSession());

        $this->actingAs($this->nguoiDung)
            ->get(route('health.result', $phien->ma_sl))
            ->assertOk()
            ->assertSee(__('messages.health.product_has_warning'));
    }

    public function test_opening_the_result_page_queues_a_briefing_that_was_never_generated(): void
    {
        // Sessions finalised before this feature existed, and sessions whose dispatch
        // hit a dead queue, have no briefing and no job. The first view must queue one
        // rather than leaving the page permanently thin.
        $this->fakeToolCall($this->fullBriefing());

        $phien = $this->triageSession();

        $this->actingAs($this->nguoiDung)
            ->get(route('health.result', $phien->ma_sl))
            ->assertOk()
            ->assertSee(__('messages.health.briefing_title'));

        $this->assertSame('ok', $phien->refresh()->ai_briefing_status);
        $this->assertSame(1, AiJob::where('kind', AiJob::KIND_TRIAGE_BRIEFING)->count());
    }

    public function test_a_failed_briefing_is_retried_once_and_not_on_every_reload(): void
    {
        // One dead attempt is transient; a second is a pattern. Retrying per page view
        // would hammer the provider for as long as the customer keeps the tab open, so
        // the budget is two attempts and no more.
        Http::fake(['*' => Http::response('gateway down', 502)]);

        $phien = $this->triageSession();

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($this->nguoiDung)->get(route('health.result', $phien->ma_sl))->assertOk();
        }

        $this->assertSame('failed', $phien->refresh()->ai_briefing_status);
        $this->assertSame(2, AiJob::where('kind', AiJob::KIND_TRIAGE_BRIEFING)->count());
    }

    public function test_the_page_shows_the_waiting_state_while_a_worker_is_writing(): void
    {
        $phien = $this->triageSession();

        AiJob::create(['uuid' => (string) Str::uuid(),
            'ma_nd' => $this->nguoiDung->ma_nd,
            'kind' => AiJob::KIND_TRIAGE_BRIEFING,
            'status' => 'running',
            'payload' => ['ma_sl' => $phien->ma_sl],
        ]);

        $this->actingAs($this->nguoiDung)
            ->get(route('health.result', $phien->ma_sl))
            ->assertOk()
            ->assertSee(__('messages.health.briefing_pending'));

        // The existing job is polled, not duplicated.
        $this->assertSame(1, AiJob::where('kind', AiJob::KIND_TRIAGE_BRIEFING)->count());
    }

    public function test_the_audit_row_records_status_but_no_briefing_text(): void
    {
        $this->fakeToolCall($this->fullBriefing());

        $this->generate($this->triageSession());

        $log = NhatKyKiemToan::where('action', 'triage_ai_briefing')->firstOrFail();
        $encoded = json_encode($log->new_values, JSON_UNESCAPED_UNICODE);

        $this->assertSame('ok', $log->new_values['ai_briefing_status']);
        $this->assertStringNotContainsString('dấu hiệu nguy hiểm', $encoded);
        $this->assertStringNotContainsString('toi bi sot nhe', $encoded);
    }
}
