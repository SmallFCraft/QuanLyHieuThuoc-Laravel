<?php

namespace Tests\Feature;

use App\Models\NhatKyKiemToan;
use App\Models\TinNhanChat;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI-drafted pharmacist reply.
 *
 * The invariant under test: a draft is never persisted and never reaches the
 * customer on its own. The pharmacist stays the validator.
 */
class SangLocAiSoanLoiTest extends TestCase
{
    use RefreshDatabase;

    protected NguoiDung $pharmacist;
    protected NguoiDung $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Only in the AI test files: an un-faked provider call must fail loudly here
        // rather than be swallowed by the degrade path.
        Http::preventStrayRequests();

        config()->set('chatbot.ai.triage.pharmacist_draft', true);

        $this->pharmacist = NguoiDung::factory()->create(['vai_tro' => 'Pharmacist', 'trang_thai' => 'active']);
        $this->customer = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
    }

    protected function fakeProviderReply(string $content): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
        ])]);
    }

    protected function pendingSession(array $attributes = []): PhienSangLoc
    {
        return PhienSangLoc::create(array_merge([
            'ma_nd' => $this->customer->ma_nd,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'pending_pharmacist',
            'outcome' => 'pharmacist_review',
            'advice_given' => 'Thông tin chưa đủ để định hướng an toàn.',
            'safety_net' => 'Đi khám ngay nếu sốt trên 39 độ quá 3 ngày.',
            'matched_symptoms' => ['fever'],
            'triggered_red_flags' => [],
            'collected_data' => ['raw_symptom_text' => 'toi bi sot va dau dau'],
            'pharmacist_id' => $this->pharmacist->ma_nd,
        ], $attributes));
    }

    public function test_tat_co_hieu_an_nut_va_tu_choi_tuyen(): void
    {
        config()->set('chatbot.ai.triage.pharmacist_draft', false);

        $phien = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->get(route('duoc-si.sang-loc.show', $phien->ma_sl))
            ->assertOk()
            ->assertDontSee(__('messages.pharmacist.btn_ai_draft'));

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.draft', $phien->ma_sl))
            ->assertSessionHasErrors('pharmacist_note');

        Http::assertNothingSent();
    }

    public function test_ban_nhap_dien_san_o_vung_van_ban_ma_khong_luu_gi(): void
    {
        $this->fakeProviderReply('Dạ, với tình trạng anh/chị mô tả, em khuyên nên nghỉ ngơi và theo dõi thêm.');

        $phien = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.draft', $phien->ma_sl))
            ->assertRedirect(route('duoc-si.sang-loc.show', $phien->ma_sl))
            ->assertSessionHas('success', __('messages.pharmacist.ai_draft_success'));

        $phien->refresh();

        $this->assertNull($phien->pharmacist_note, 'A draft must never be persisted.');
        $this->assertSame('pending_pharmacist', $phien->status);
        $this->assertSame(0, TinNhanChat::count(), 'A draft must never reach the chat thread.');

        // The draft comes back through withInput(), so old() fills the existing form.
        $this->actingAs($this->pharmacist)
            ->get(route('duoc-si.sang-loc.show', $phien->ma_sl))
            ->assertOk()
            ->assertSee('em khuyên nên nghỉ ngơi', false);
    }

    public function test_loi_nhac_khong_chua_van_ban_tu_do_hay_du_lieu_ho_so(): void
    {
        $this->fakeProviderReply('Dạ, em xin phép trả lời anh/chị như sau.');

        \App\Models\HoSoSucKhoe::create(['ma_nd' => $this->customer->ma_nd,
            'allergies' => ['penicillin'],
            'chronic_conditions' => ['loét dạ dày'],
            'current_medications' => ['omeprazole'],
            'consented_at' => now(),
        ]);

        $phien = $this->pendingSession();

        $this->actingAs($this->pharmacist)->post(route('duoc-si.sang-loc.draft', $phien->ma_sl));

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

    public function test_ca_chua_nhan_bi_tu_choi_truoc_khi_goi_nha_cung_cap(): void
    {
        $phien = $this->pendingSession(['pharmacist_id' => null]);

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.draft', $phien->ma_sl))
            ->assertSessionHasErrors('pharmacist_note');

        Http::assertNothingSent();
    }

    public function test_ca_do_duoc_si_khac_nhan_bi_tu_choi(): void
    {
        $other = NguoiDung::factory()->create(['vai_tro' => 'Pharmacist', 'trang_thai' => 'active']);
        $phien = $this->pendingSession(['pharmacist_id' => $other->ma_nd]);

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.draft', $phien->ma_sl))
            ->assertSessionHasErrors('pharmacist_note');

        Http::assertNothingSent();
    }

    public function test_ca_da_duyet_bi_tu_choi(): void
    {
        $phien = $this->pendingSession([
            'status' => 'reviewed',
            'pharmacist_note' => 'Đã trả lời.',
            'reviewed_at' => now(),
        ]);

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.draft', $phien->ma_sl))
            ->assertSessionHasErrors('pharmacist_note');

        Http::assertNothingSent();
    }

    public function test_khong_the_truy_cap_phien_nam_ngoai_hang_doi(): void
    {
        $phien = $this->pendingSession(['status' => 'in_progress', 'outcome' => null]);

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.draft', $phien->ma_sl))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_loi_nha_cung_cap_de_bieu_mau_trong(): void
    {
        Http::fake(['*' => Http::response('gateway down', 502)]);

        $phien = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.draft', $phien->ma_sl))
            ->assertSessionHasErrors('pharmacist_note');

        $this->assertNull($phien->fresh()->pharmacist_note);
    }

    public function test_phan_hoi_rong_cua_nha_cung_cap_duoc_xem_la_loi(): void
    {
        $this->fakeProviderReply('   ');

        $phien = $this->pendingSession();

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.draft', $phien->ma_sl))
            ->assertSessionHasErrors('pharmacist_note');
    }

    public function test_markdown_bi_loai_vi_vung_van_ban_hien_van_ban_thuan(): void
    {
        $this->fakeProviderReply("## Trả lời\n\n**Dạ**, anh/chị nên `nghỉ ngơi` và [đi khám](http://x.test).");

        $phien = $this->pendingSession();

        $this->actingAs($this->pharmacist)->post(route('duoc-si.sang-loc.draft', $phien->ma_sl));

        $draft = session('_old_input.pharmacist_note');

        $this->assertStringNotContainsString('##', $draft);
        $this->assertStringNotContainsString('**', $draft);
        $this->assertStringNotContainsString('`', $draft);
        $this->assertStringNotContainsString('http://x.test', $draft);
        $this->assertStringContainsString('đi khám', $draft);
    }

    public function test_tien_to_mode_cua_nha_cung_cap_bi_loai_bo(): void
    {
        $this->fakeProviderReply("[MODE: EXECUTE]\n\nDạ em chào anh/chị, tình trạng ho 3 ngày nay cần theo dõi thêm.");

        $phien = $this->pendingSession();

        $this->actingAs($this->pharmacist)->post(route('duoc-si.sang-loc.draft', $phien->ma_sl));

        $draft = session('_old_input.pharmacist_note');

        $this->assertStringNotContainsString('[MODE', $draft);
        $this->assertStringStartsWith('Dạ em chào anh/chị', $draft);
    }

    public function test_tien_to_mode_khong_dau_xuong_dong_bi_loai_bo(): void
    {
        $this->fakeProviderReply('[MODE: EXPLORE] Dạ em chào anh/chị, em xin hỏi thêm vài thông tin.');

        $phien = $this->pendingSession();

        $this->actingAs($this->pharmacist)->post(route('duoc-si.sang-loc.draft', $phien->ma_sl));

        $draft = session('_old_input.pharmacist_note');

        $this->assertStringNotContainsString('[MODE', $draft);
        $this->assertStringStartsWith('Dạ em chào anh/chị', $draft);
    }

    public function test_dong_nhat_ky_ghi_nguon_goc_nhung_khong_ghi_noi_dung(): void
    {
        $this->fakeProviderReply('Dạ, em trả lời anh/chị như sau.');

        $phien = $this->pendingSession();

        $this->actingAs($this->pharmacist)->post(route('duoc-si.sang-loc.draft', $phien->ma_sl));

        $log = NhatKyKiemToan::where('action', 'triage_ai_draft')->firstOrFail();
        $values = $log->new_values;

        $this->assertSame('ok', $values['ai_status']);
        $this->assertSame('pharmacist_review', $values['outcome']);
        $this->assertArrayHasKey('ai_provider_model', $values);
        $this->assertArrayHasKey('processing_time_ms', $values);

        $encoded = json_encode($values, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('em trả lời anh/chị', $encoded);
        $this->assertStringNotContainsString('toi bi sot', $encoded);
    }

    public function test_nguoi_dung_thuong_khong_the_truy_cap_tuyen_soan_loi(): void
    {
        $phien = $this->pendingSession();

        $this->actingAs($this->customer)
            ->post(route('duoc-si.sang-loc.draft', $phien->ma_sl))
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
