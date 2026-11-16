<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DangKyTest extends TestCase
{
    use RefreshDatabase;

    public function test_man_hinh_dang_ky_hien_thi_duoc(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_nguoi_dung_moi_dang_ky_duoc(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('trang-chu', absolute: false));
    }
}
