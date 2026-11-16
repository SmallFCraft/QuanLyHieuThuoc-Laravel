<?php

namespace Tests\Feature\Auth;

use App\Models\NguoiDung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class XacNhanMatKhauTest extends TestCase
{
    use RefreshDatabase;

    public function test_man_hinh_xac_nhan_mat_khau_hien_thi_duoc(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $response = $this->actingAs($nguoiDung)->get('/confirm-password');

        $response->assertStatus(200);
    }

    public function test_mat_khau_xac_nhan_duoc(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $response = $this->actingAs($nguoiDung)->post('/confirm-password', [
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_mat_khau_khong_xac_nhan_duoc_voi_mat_khau_sai(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $response = $this->actingAs($nguoiDung)->post('/confirm-password', [
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors();
    }
}
