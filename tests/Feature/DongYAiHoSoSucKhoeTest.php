<?php

namespace Tests\Feature;

use App\Http\Controllers\HoSoSucKhoeController;
use App\Models\NhatKyKiemToan;
use App\Models\NguoiDung;
use App\Models\HoSoSucKhoe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DongYAiHoSoSucKhoeTest extends TestCase
{
    use RefreshDatabase;

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'allergies' => ['Penicillin'],
            'chronic_conditions' => [],
            'current_medications' => [],
            'is_pregnant' => '0',
            'is_breastfeeding' => '0',
            'consent' => '1',
        ], $overrides);
    }

    public function test_checkbox_dong_y_ai_cap_quyen_dong_y_ai(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $this->actingAs($nguoiDung)
            ->put(route('ho-so.suc-khoe.update'), $this->payload(['ai_consent' => '1']))
            ->assertSessionHasNoErrors();

        $hoSo = HoSoSucKhoe::where('ma_nd', $nguoiDung->ma_nd)->firstOrFail();

        $this->assertNotNull($hoSo->ai_consent_at);
        $this->assertTrue($hoSo->hasAiConsent());
    }

    public function test_quyen_ai_duoc_cap_ma_khong_can_dong_y_an_toan(): void
    {
        // The two consents are independent: declining pharmacist warnings must not
        // silently decline - or grant - the AI one.
        $nguoiDung = NguoiDung::factory()->create();
        $payload = $this->payload(['ai_consent' => '1']);
        unset($payload['consent']);

        $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $payload);

        $hoSo = HoSoSucKhoe::where('ma_nd', $nguoiDung->ma_nd)->firstOrFail();

        $this->assertFalse($hoSo->hasConsented());
        $this->assertTrue($hoSo->hasAiConsent());
    }

    public function test_bo_chon_dong_y_ai_thu_hoi_quyen_va_ghi_kiem_toan_chuyen_trang_thai(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $this->payload(['ai_consent' => '1']));
        $this->assertTrue(HoSoSucKhoe::where('ma_nd', $nguoiDung->ma_nd)->firstOrFail()->hasAiConsent());

        $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $this->payload());

        $hoSo = HoSoSucKhoe::where('ma_nd', $nguoiDung->ma_nd)->firstOrFail();
        $log = NhatKyKiemToan::where('action', 'health_profile_updated')->latest('ma_nk')->firstOrFail();
        $serialized = json_encode($log->getAttributes(), JSON_UNESCAPED_UNICODE);

        $this->assertNull($hoSo->ai_consent_at);
        $this->assertFalse($hoSo->hasAiConsent());
        // Only the consent transition changed, and it is named by field, not by value.
        $this->assertSame(['ai_consent_at'], $log->new_values['changed_fields']);
        $this->assertStringNotContainsString('Penicillin', $serialized);
    }

    public function test_truong_thay_doi_dong_y_ai_theo_chuyen_trang_thai_khong_theo_dau_thoi_gian(): void
    {
        // The HTTP path hands changedFields() the very same Carbon instance it read off
        // the model, so a string comparison can never differ there. The rule this guards
        // is the comparison itself: consent is a state (null vs set), not a value.
        $controller = new HoSoSucKhoeController();
        $changedFields = new \ReflectionMethod($controller, 'changedFields');

        $granted = (new HoSoSucKhoe())->forceFill(['ai_consent_at' => now()->subDay()]);
        $revoked = new HoSoSucKhoe();

        // Two distinct, non-null timestamps: same state, so nothing changed.
        $this->assertSame([], $changedFields->invoke($controller, $granted, ['ai_consent_at' => now()]));

        // Granting and revoking are real transitions and must be named.
        $this->assertSame(['ai_consent_at'], $changedFields->invoke($controller, $granted, ['ai_consent_at' => null]));
        $this->assertSame(['ai_consent_at'], $changedFields->invoke($controller, $revoked, ['ai_consent_at' => now()]));
    }

    public function test_loi_form_khac_khong_bo_chon_cac_o_dong_y(): void
    {
        // The profile page hosts profile-info, password, avatar and health forms. A
        // validation error in any of them flashes _old_input, but only the health form
        // may influence the health checkboxes — otherwise a mistyped phone number
        // silently revokes medical consent on the next save.
        $nguoiDung = NguoiDung::factory()->create();
        HoSoSucKhoe::create(['ma_nd' => $nguoiDung->ma_nd,
            'consented_at' => now(),
            'ai_consent_at' => now(),
        ]);

        $this->actingAs($nguoiDung)
            ->from(route('ho-so.edit'))
            ->patch(route('ho-so.update'), [
                'name' => $nguoiDung->ho_ten,
                'email' => $nguoiDung->email,
                'phone' => 'not-a-phone',
            ])
            ->assertSessionHasErrors('phone');

        $html = $this->actingAs($nguoiDung)->get(route('ho-so.edit'))->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]+id="health_consent"[^>]+checked/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]+id="health_ai_consent"[^>]+checked/', $html);
    }

    public function test_redirect_khi_loi_xac_thuc_giu_ca_hai_o_dong_y_o_trang_thai_bo_chon(): void
    {
        $nguoiDung = NguoiDung::factory()->create();
        HoSoSucKhoe::create(['ma_nd' => $nguoiDung->ma_nd,
            'consented_at' => now(),
            'ai_consent_at' => now(),
        ]);

        $response = $this->actingAs($nguoiDung)
            ->from(route('ho-so.edit'))
            ->put(route('ho-so.suc-khoe.update'), ['weight_kg' => '501']);

        $response->assertRedirect(route('ho-so.edit'))
            ->assertSessionHasErrors('weight_kg');

        $html = $this->actingAs($nguoiDung)->get(route('ho-so.edit'))->getContent();

        $this->assertDoesNotMatchRegularExpression('/<input[^>]+id="health_consent"[^>]+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]+id="health_ai_consent"[^>]+checked/', $html);
    }

    public function test_cap_lai_quyen_ai_giu_dau_thoi_gian_ban_dau(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $this->payload(['ai_consent' => '1']));
        $grantedAt = HoSoSucKhoe::where('ma_nd', $nguoiDung->ma_nd)->firstOrFail()->ai_consent_at;

        $this->travel(5)->minutes();

        $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $this->payload(['ai_consent' => '1']));

        $this->assertTrue($grantedAt->equalTo(
            HoSoSucKhoe::where('ma_nd', $nguoiDung->ma_nd)->firstOrFail()->ai_consent_at
        ));
    }

    public function test_nhan_o_checkbox_dong_y_ai_duoc_hien_thi(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $response = $this->actingAs($nguoiDung)->get(route('ho-so.edit'));

        // Colour alone is not the state indicator, and the label wraps the text so
        // the click target and the accessible name both belong to the input.
        $response->assertOk()
            ->assertSee('id="health_ai_consent"', false)
            ->assertSee('for="health_ai_consent"', false)
            ->assertSee(__('messages.health_profile.ai_consent_title'))
            ->assertSee(__('messages.health_profile.ai_consent_hint'));
    }

    public function test_ho_so_suc_khoe_theo_dung_trang_thai_dong_y_ai(): void
    {
        $nguoiDung = NguoiDung::factory()->create();
        $hoSo = HoSoSucKhoe::create(['ma_nd' => $nguoiDung->ma_nd,
            'consented_at' => now(),
            'ai_consent_at' => null,
        ]);

        $this->assertFalse($hoSo->hasAiConsent());

        $hoSo->update(['ai_consent_at' => now()]);
        $hoSo->refresh();

        $this->assertTrue($hoSo->hasAiConsent());
        $this->assertNotNull($hoSo->ai_consent_at);
    }
}
