<?php

namespace Tests\Feature\Auth;

use App\Models\NguoiDung;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $nguoiDung = NguoiDung::factory()->unverified()->create();

        $response = $this->actingAs($nguoiDung)->get('/verify-email');

        $response->assertStatus(200);
    }

    public function test_email_can_be_verified(): void
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
        $response->assertRedirect(route('home', absolute: false).'?verified=1');
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
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
