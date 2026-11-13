<?php

namespace Tests\Feature;

use App\Models\NguoiDung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HoSoTest extends TestCase
{
    use RefreshDatabase;

    public function test_trang_ho_so_duoc_hien_thi(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $response = $this
            ->actingAs($nguoiDung)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_thong_tin_ho_so_cap_nhat_duoc(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $response = $this
            ->actingAs($nguoiDung)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $nguoiDung->refresh();

        $this->assertSame('Test User', $nguoiDung->ho_ten);
        $this->assertSame('test@example.com', $nguoiDung->email);
        $this->assertNull($nguoiDung->thoi_diem_xac_thuc_email);
    }

    public function test_trang_thai_xac_thuc_email_khong_doi_khi_dia_chi_email_khong_doi(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $response = $this
            ->actingAs($nguoiDung)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $nguoiDung->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($nguoiDung->refresh()->thoi_diem_xac_thuc_email);
    }

    public function test_nguoi_dung_xoa_duoc_tai_khoan(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $response = $this
            ->actingAs($nguoiDung)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($nguoiDung->fresh());
    }

    public function test_xoa_tai_khoan_can_mat_khau_chinh_xac(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $response = $this
            ->actingAs($nguoiDung)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($nguoiDung->fresh());
    }
}
