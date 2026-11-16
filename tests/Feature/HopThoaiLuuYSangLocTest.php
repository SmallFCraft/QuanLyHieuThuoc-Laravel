<?php

namespace Tests\Feature;

use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use App\Services\Triage\SangLocYTeService;
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
class HopThoaiLuuYSangLocTest extends TestCase
{
    use RefreshDatabase;

    protected NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
    }

    protected function completedSession(array $attributes = []): PhienSangLoc
    {
        return PhienSangLoc::create(array_merge([
            'ma_nd' => $this->nguoiDung->ma_nd,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'pharmacist_review',
            'advice_given' => 'Anh/chị theo dõi thêm và đi khám nếu nặng lên.',
            'collected_data' => ['raw_symptom_text' => 'dau dau'],
        ], $attributes));
    }

    public function test_hop_thoai_hien_o_lan_truy_cap_dau_trong_ngay(): void
    {
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
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

    public function test_tham_so_luu_y_bang_khong_an_hop_thoai_chi_cho_url_do(): void
    {
        // Escape and a scrim click mean "not now", so they must not write the daily flag —
        // but the result page reloads itself when a polled AI job lands. The close handler
        // records the refusal in the URL so those reloads do not re-steal focus.
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra', ['disclaimer' => 0]))
            ->assertOk()
            ->assertDontSee('data-disclaimer-popup', false);

        // Nothing was persisted: a fresh visit asks again.
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertSee('role="dialog"', false);

        $this->assertFalse(Cache::has(
            "triage:disclaimer_ack:{$this->nguoiDung->ma_nd}:" . today()->toDateString()
        ));
    }

    public function test_lien_ket_xem_lai_van_co_khi_hop_thoai_dang_mo(): void
    {
        // Escape closes the modal client-side without a reload, so a notice with no way
        // back to the full text would be a dead end for the rest of the day.
        //
        // Anchored on the href, not the label: "Xem lại" also appears in a CSS comment in
        // the styles partial, which @stack('styles') puts in <head>, so asserting the
        // label alone passes with the link deleted.
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertSee('data-disclaimer-popup', false)
            ->assertSee('disclaimer=1', false)
            ->assertSee('class="health-disclaimer__body', false);
    }

    public function test_thong_bao_giu_lop_than_bai_chi_cho_trinh_doc_man_hinh_ma_ban_in_khoi_phuc(): void
    {
        // The class carries both the clip-path that compacts the notice on screen and the
        // @media print rule that brings the full text back for the clinic copy. Without an
        // anchor on it, the notice could regress to the always-visible banner — or lose the
        // printout — with the suite green.
        $phien = $this->completedSession();

        foreach ([route('sang-loc.kiem-tra'), route('sang-loc.ket-qua', $phien->ma_sl)] as $url) {
            $this->actingAs($this->nguoiDung)
                ->get($url)
                ->assertOk()
                ->assertSee('class="health-disclaimer__body', false);
        }

        $styles = view('health.partials.styles')->render();
        $this->assertStringContainsString('clip-path: inset(50%)', $styles);
        $this->assertStringContainsString('.health-disclaimer__body {', $styles);
    }

    public function test_hop_thoai_khong_hien_lai_sau_khi_da_dong(): void
    {
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertSee('data-disclaimer-popup', false);

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.disclaimer.xac-nhan'))
            ->assertRedirect(route('sang-loc.kiem-tra'));

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertDontSee('data-disclaimer-popup', false);
    }

    public function test_thong_bao_pham_vi_van_day_du_tren_ca_hai_trang_sau_khi_dong(): void
    {
        $phien = $this->completedSession();

        $this->actingAs($this->nguoiDung)->post(route('sang-loc.disclaimer.xac-nhan'));

        foreach ([route('sang-loc.kiem-tra'), route('sang-loc.ket-qua', $phien->ma_sl)] as $url) {
            $this->actingAs($this->nguoiDung)
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

    public function test_lien_ket_xem_lai_mo_lai_hop_thoai_ma_khong_xoa_co_hang_ngay(): void
    {
        $this->actingAs($this->nguoiDung)->post(route('sang-loc.disclaimer.xac-nhan'));

        // Reopening is a read: the popup comes back for this request only.
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra', ['disclaimer' => 1]))
            ->assertOk()
            ->assertSee('data-disclaimer-popup', false);

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertDontSee('data-disclaimer-popup', false);
    }

    public function test_lien_ket_xem_lai_cung_mo_lai_hop_thoai_tren_trang_ket_qua(): void
    {
        $phien = $this->completedSession();

        $this->actingAs($this->nguoiDung)->post(route('sang-loc.disclaimer.xac-nhan'));

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', ['MaSL' => $phien->ma_sl, 'disclaimer' => 1]))
            ->assertOk()
            ->assertSee('data-disclaimer-popup', false);
    }

    public function test_ket_qua_cap_cuu_cung_bo_qua_lien_ket_xem_lai(): void
    {
        // The tel:115 button is the only action on that page. Not even an explicit
        // request may put a dismissible sheet over it.
        $phien = $this->completedSession([
            'outcome' => 'emergency',
            'advice_given' => 'Anh/chị gọi ngay 115.',
        ]);

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', ['MaSL' => $phien->ma_sl, 'disclaimer' => 1]))
            ->assertOk()
            ->assertDontSee('data-disclaimer-popup', false);
    }

    public function test_hop_thoai_hien_lai_vao_ngay_tiep_theo(): void
    {
        $this->actingAs($this->nguoiDung)->post(route('sang-loc.disclaimer.xac-nhan'));

        // The calendar day is in the cache key, so tomorrow is a different key regardless
        // of how much of the TTL is left.
        $this->travel(1)->day();

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertSee('data-disclaimer-popup', false);

        $this->travelBack();
    }

    public function test_trang_ket_qua_gan_ma_phien_vao_bieu_mau_dong(): void
    {
        // The producer of the MaSL field is the @include's argument on the result page.
        // POSTing MaSL directly leaves that wiring untested, so render it first and post
        // exactly what the page emits.
        $phien = $this->completedSession();

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->assertSee('name="MaSL" value="' . $phien->ma_sl . '"', false);

        // And the check page has no session id to wire, so it must not invent one.
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertDontSee('name="MaSL"', false);
    }

    public function test_dong_tu_trang_ket_qua_quay_lai_trang_do(): void
    {
        $phien = $this->completedSession();

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.disclaimer.xac-nhan'), ['ma_sl' => $phien->ma_sl])
            ->assertRedirect(route('sang-loc.ket-qua', $phien->ma_sl));
    }

    public function test_ma_phien_rac_bi_tu_choi_thay_vi_tao_url_hong(): void
    {
        // 0 and negatives pass a bare `integer` rule and generate URLs that only 404 on
        // arrival, so min:1 rejects them at the door instead.
        foreach ([0, -5] as $junk) {
            $this->actingAs($this->nguoiDung)
                ->post(route('sang-loc.disclaimer.xac-nhan'), ['ma_sl' => $junk])
                ->assertSessionHasErrors('ma_sl');
        }

        // Rejected input must not have silenced the popup either.
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertSee('data-disclaimer-popup', false);
    }

    public function test_ma_phien_rong_quay_ve_quy_trinh(): void
    {
        // ConvertEmptyStringsToNull turns '' into null before validation, so a stray
        // empty hidden input takes the "no MaSL" branch rather than reaching route(),
        // which would throw UrlGenerationException and 500.
        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.disclaimer.xac-nhan'), ['ma_sl' => ''])
            ->assertRedirect(route('sang-loc.kiem-tra'));
    }

    public function test_ma_phien_cua_nguoi_khac_khong_the_dung_de_tham_do(): void
    {
        // The redirect is built from a named route and an integer, so there is no
        // open-redirect surface; result() then 404s because it scopes by MaKH.
        $other = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
        $theirs = PhienSangLoc::create(['ma_nd' => $other->ma_nd,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'self_care',
            'advice_given' => 'x',
            'collected_data' => ['raw_symptom_text' => 'x'],
        ]);

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.disclaimer.xac-nhan'), ['ma_sl' => $theirs->ma_sl])
            ->assertRedirect(route('sang-loc.ket-qua', $theirs->ma_sl));

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $theirs->ma_sl))
            ->assertNotFound();
    }

    public function test_ket_qua_cap_cuu_khong_bao_gio_bi_hop_thoai_che(): void
    {
        // The tel:115 button is the only action on that page; a dismissible sheet must
        // not share the viewport with it. The notice itself still renders — and because
        // there is no popup to reopen, it renders inline rather than offering a "Xem lại"
        // link to nothing.
        $phien = $this->completedSession([
            'outcome' => 'emergency',
            'advice_given' => 'Anh/chị gọi ngay 115.',
        ]);

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->assertDontSee('data-disclaimer-popup', false)
            ->assertSee(__('messages.health.disclaimer_body'))
            ->assertDontSee('disclaimer=1', false)
            ->assertDontSee('class="health-disclaimer__body', false);
    }

    public function test_xac_nhan_cua_mot_nguoi_khong_an_hop_thoai_voi_nguoi_khac(): void
    {
        // The shared in-store tablet case, and the assertion a cookie or localStorage
        // design cannot pass.
        $other = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);

        $this->actingAs($this->nguoiDung)->post(route('sang-loc.disclaimer.xac-nhan'));

        $this->actingAs($other)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertSee('data-disclaimer-popup', false);
    }

    public function test_xac_nhan_duoc_dinh_danh_theo_nguoi_dung_va_ngay(): void
    {
        $this->actingAs($this->nguoiDung)->post(route('sang-loc.disclaimer.xac-nhan'));

        $this->assertTrue(Cache::has(
            "triage:disclaimer_ack:{$this->nguoiDung->ma_nd}:" . today()->toDateString()
        ));
    }

    public function test_thay_giai_doan_ajax_khong_bao_gio_mang_theo_hop_thoai(): void
    {
        // The non-nagging invariant the controller's own comment claims: the popup is
        // built only in index()'s terminal return, so no AJAX step swap can re-render it
        // mid-wizard and re-steal focus. The swapped payload is HTML for [data-triage-stage].
        $this->actingAs($this->nguoiDung)->get(route('sang-loc.kiem-tra'));
        $phien = PhienSangLoc::where('ma_nd', $this->nguoiDung->ma_nd)->inProgress()->firstOrFail();

        $response = $this->actingAs($this->nguoiDung)
            ->postJson(route('sang-loc.tra-loi'), [
                'triage_session_id' => $phien->ma_sl,
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
            ->assertJsonPath('disclaimer', SangLocYTeService::DISCLAIMER);
    }
}
