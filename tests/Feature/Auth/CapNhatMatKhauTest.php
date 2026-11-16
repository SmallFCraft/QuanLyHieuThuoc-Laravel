<?php

namespace Tests\Feature\Auth;

use App\Models\NguoiDung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CapNhatMatKhauTest extends TestCase
{
    use RefreshDatabase;

    public function test_mat_khau_cap_nhat_duoc(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $response = $this
            ->actingAs($nguoiDung)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('new-password', $nguoiDung->refresh()->password));
    }

    public function test_cap_nhat_mat_khau_can_mat_khau_hien_tai_chinh_xac(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $response = $this
            ->actingAs($nguoiDung)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/profile');
    }
}
