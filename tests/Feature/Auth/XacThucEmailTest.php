<?php

namespace Tests\Feature\Auth;

use App\Models\NguoiDung;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class XacThucEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_man_hinh_xac_thuc_email_hien_thi_duoc(): void
    {
        $nguoiDung = NguoiDung::factory()->unverified()->create();

        $response = $this->actingAs($nguoiDung)->get('/verify-email');

        $response->assertStatus(200);
    }

    public function test_email_xac_thuc_duoc(): void
    {
        $nguoiDung = NguoiDung::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $nguoiDung->ma_nd, 'hash' => sha1($nguoiDung->email)]
        );

        $response = $this->actingAs($nguoiDung)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($nguoiDung->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('trang-chu', absolute: false).'?verified=1');
    }

    public function test_email_khong_xac_thuc_voi_hash_khong_hop_le(): void
    {
        $nguoiDung = NguoiDung::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $nguoiDung->ma_nd, 'hash' => sha1('wrong-email')]
        );

        $this->actingAs($nguoiDung)->get($verificationUrl);

        $this->assertFalse($nguoiDung->fresh()->hasVerifiedEmail());
    }
}
