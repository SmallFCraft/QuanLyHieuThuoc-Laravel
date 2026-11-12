<?php

namespace Tests\Feature;

use App\Models\NhatKyKiemToan;
use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\NguoiDung;
use App\Models\HoSoSucKhoe;
use Database\Seeders\TriThucYTeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartDrugSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected DanhMuc $danhMuc;
    protected NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        // TriThucYTeSeeder resolves categories by name, so it must exist first.
        $this->danhMuc = DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt',
            'mo_ta' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(TriThucYTeSeeder::class);

        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active',
            'ngay_sinh' => now()->subYears(34)->toDateString(),
        ]);
    }

    public function test_major_interaction_blocks_the_second_item(): void
    {
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $this->actingAs($this->nguoiDung)->post(route('cart.add'), [
            'ma_sp' => $aspirin->ma_sp,
            'quantity' => 1,
        ])->assertSessionHas('success');

        $response = $this->actingAs($this->nguoiDung)->post(route('cart.add'), [
            'ma_sp' => $ibuprofen->ma_sp,
            'quantity' => 1,
        ]);

        $response->assertRedirect(route('cart.index'));
        $response->assertSessionHas('drug_warnings');

        $cart = session('cart');
        $this->assertCount(1, $cart);
        $this->assertArrayNotHasKey($ibuprofen->ma_sp, $cart);

        // The cart page must render the acknowledgement form that replays the request.
        $this->actingAs($this->nguoiDung)->get(route('cart.index'))
            ->assertOk()
            ->assertSee('name="confirm_warning"', false)
            ->assertSee('bi-exclamation-octagon-fill', false);
    }

    public function test_confirmation_adds_the_item_and_records_the_override(): void
    {
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        session(['cart' => [$aspirin->ma_sp => 1]]);

        $response = $this->actingAs($this->nguoiDung)->post(route('cart.add'), [
            'ma_sp' => $ibuprofen->ma_sp,
            'quantity' => 1,
            'confirm_warning' => 1,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $cart = session('cart');
        $this->assertCount(2, $cart);
        $this->assertArrayHasKey($ibuprofen->ma_sp, $cart);

        $log = NhatKyKiemToan::where('action', 'drug_warning_overridden')->first();
        $this->assertNotNull($log);
        $this->assertSame($ibuprofen->ma_sp, $log->MaDoiTuong);
    }

    public function test_declared_allergy_blocks_the_matching_product(): void
    {
        $this->healthProfile(['allergies' => ['Paracetamol'], 'consented_at' => now()]);
        $panadol = $this->product('Panadol', 'Paracetamol 500mg');

        $response = $this->actingAs($this->nguoiDung)->post(route('cart.add'), [
            'ma_sp' => $panadol->ma_sp,
            'quantity' => 1,
        ]);

        $response->assertSessionHas('drug_warnings');
        $this->assertEmpty(session('cart', []));
    }

    public function test_profile_without_consent_is_not_read(): void
    {
        $this->healthProfile(['allergies' => ['Paracetamol'], 'consented_at' => null]);
        $panadol = $this->product('Panadol', 'Paracetamol 500mg');

        $response = $this->actingAs($this->nguoiDung)->post(route('cart.add'), [
            'ma_sp' => $panadol->ma_sp,
            'quantity' => 1,
        ]);

        $response->assertSessionHas('success');
        $response->assertSessionMissing('drug_warnings');
        $this->assertArrayHasKey($panadol->ma_sp, session('cart'));
    }

    public function test_unrelated_product_is_added_without_warnings(): void
    {
        $vitamin = $this->product('Vitamin E', 'Vitamin E 400IU');

        $response = $this->actingAs($this->nguoiDung)->post(route('cart.add'), [
            'ma_sp' => $vitamin->ma_sp,
            'quantity' => 2,
        ]);

        $response->assertSessionHas('success');
        $response->assertSessionMissing('drug_warnings');
        $this->assertEquals(2, session('cart')[$vitamin->ma_sp]);
    }

    public function test_json_branch_reports_that_confirmation_is_required(): void
    {
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        session(['cart' => [$aspirin->ma_sp => 1]]);

        $response = $this->actingAs($this->nguoiDung)->postJson(route('cart.add'), [
            'ma_sp' => $ibuprofen->ma_sp,
            'quantity' => 1,
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'success' => false,
            'requires_confirmation' => true,
        ]);
        $this->assertNotEmpty($response->json('drug_warnings'));
        $this->assertArrayNotHasKey($ibuprofen->ma_sp, session('cart'));
    }

    public function test_json_branch_confirmation_adds_the_item(): void
    {
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        session(['cart' => [$aspirin->ma_sp => 1]]);

        $response = $this->actingAs($this->nguoiDung)->postJson(route('cart.add'), [
            'ma_sp' => $ibuprofen->ma_sp,
            'quantity' => 1,
            'confirm_warning' => 1,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'requires_confirmation' => false,
            'cart_count' => 2,
        ]);
    }

    public function test_soft_warning_is_returned_without_blocking(): void
    {
        $panadol = $this->product('Panadol', 'Paracetamol 500mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        session(['cart' => [$panadol->ma_sp => 1]]);

        $response = $this->actingAs($this->nguoiDung)->postJson(route('cart.add'), [
            'ma_sp' => $ibuprofen->ma_sp,
            'quantity' => 1,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'requires_confirmation' => false]);
        $this->assertNotEmpty($response->json('drug_warnings'));
        $this->assertArrayHasKey($ibuprofen->ma_sp, session('cart'));
    }

    public function test_checkout_shows_the_warnings_but_still_allows_ordering(): void
    {
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        session(['cart' => [$aspirin->ma_sp => 1, $ibuprofen->ma_sp => 1]]);

        $response = $this->actingAs($this->nguoiDung)->get(route('checkout.index'));

        $response->assertOk();
        $response->assertViewIs('checkout.index');
        // Structural marker, not the label: the lang key is wired into messages.php separately.
        $response->assertSee('bi-exclamation-octagon-fill', false);
        $response->assertSee('role="alert"', false);
        $this->assertNotEmpty($response->viewData('drugWarnings'));
    }

    public function test_empty_cart_checkout_does_not_error(): void
    {
        $response = $this->actingAs($this->nguoiDung)->get(route('checkout.index'));

        $response->assertRedirect(route('cart.index'));
        $response->assertSessionHas('error');
    }

    protected function healthProfile(array $attributes = []): HoSoSucKhoe
    {
        return HoSoSucKhoe::updateOrCreate(
            ['ma_nd' => $this->nguoiDung->ma_nd],
            array_merge(['consented_at' => now()], $attributes)
        );
    }

    protected function product(string $name, string $ingredients): SanPham
    {
        return SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => $name,
            'thanh_phan' => $ingredients,
            'cach_dung' => 'Theo hướng dẫn',
            'gia' => 5000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 50,
            'muc_canh_bao' => 5,
            'so_lo' => 'B-' . uniqid(),
        ]);
    }
}
