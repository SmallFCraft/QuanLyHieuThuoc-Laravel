<?php

namespace Tests\Feature\Auth;

use App\Models\NguoiDung;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DatLaiMatKhauTest extends TestCase
{
    use RefreshDatabase;

    public function test_man_hinh_link_dat_lai_mat_khau_hien_thi_duoc(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_link_dat_lai_mat_khau_yeu_cau_duoc(): void
    {
        Notification::fake();

        $nguoiDung = NguoiDung::factory()->create();

        $this->post('/forgot-password', ['email' => $nguoiDung->email]);

        Notification::assertSentTo($nguoiDung, ResetPassword::class);
    }

    public function test_man_hinh_dat_lai_mat_khau_hien_thi_duoc(): void
    {
        Notification::fake();

        $nguoiDung = NguoiDung::factory()->create();

        $this->post('/forgot-password', ['email' => $nguoiDung->email]);

        Notification::assertSentTo($nguoiDung, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_mat_khau_dat_lai_duoc_voi_token_hop_le(): void
    {
        Notification::fake();

        $nguoiDung = NguoiDung::factory()->create();

        $this->post('/forgot-password', ['email' => $nguoiDung->email]);

        Notification::assertSentTo($nguoiDung, ResetPassword::class, function ($notification) use ($nguoiDung) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $nguoiDung->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }
}
