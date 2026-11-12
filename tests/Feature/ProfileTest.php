<?php

namespace Tests\Feature;

use App\Models\NguoiDung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $response = $this
            ->actingAs($nguoiDung)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
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

        $this->assertSame('Test User', $nguoiDung->name);
        $this->assertSame('test@example.com', $nguoiDung->email);
        $this->assertNull($nguoiDung->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
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

        $this->assertNotNull($nguoiDung->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
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

    public function test_correct_password_must_be_provided_to_delete_account(): void
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
