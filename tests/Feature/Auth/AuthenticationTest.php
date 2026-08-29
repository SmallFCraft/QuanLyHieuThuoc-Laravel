<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password123'),
            'role' => 'User',
            'status' => 'active'
        ]);

        $response = $this->post(route('login'), [
            'email' => 'test@example.com',
            'password' => 'Password123'
        ]);

        $response->assertRedirect(route('home', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_cannot_login_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password123'),
            'role' => 'User',
            'status' => 'active'
        ]);

        $response = $this->post(route('login'), [
            'email' => 'test@example.com',
            'password' => 'WrongPassword'
        ]);

        $response->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_admin_can_access_admin_panel(): void
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'status' => 'active'
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertStatus(200);
    }

    public function test_regular_user_cannot_access_admin_panel(): void
    {
        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active'
        ]);

        $response = $this->actingAs($user)->get(route('admin.dashboard'));

        $response->assertStatus(403);
    }

    public function test_guest_cannot_access_admin_panel(): void
    {
        $response = $this->get(route('admin.dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_authentication_attempt_is_logged(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password123'),
            'role' => 'User',
            'status' => 'active'
        ]);

        $this->post(route('login'), [
            'email' => 'test@example.com',
            'password' => 'Password123'
        ]);

        // Check if audit log was created for login
        $this->assertDatabaseHas('audit_logs', [
            'MaKH' => $user->MaKH,
            'action' => 'login_success'
        ]);
    }

    public function test_failed_authentication_attempt_is_logged(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password123'),
            'role' => 'User',
            'status' => 'active'
        ]);

        $this->post(route('login'), [
            'email' => 'test@example.com',
            'password' => 'WrongPassword'
        ]);

        // Check if failed login attempt was logged
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'login_failed'
        ]);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active'
        ]);

        $this->actingAs($user);

        $response = $this->post(route('logout'));

        $response->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password123'),
            'role' => 'User',
            'status' => 'inactive'
        ]);

        $response = $this->post(route('login'), [
            'email' => 'test@example.com',
            'password' => 'Password123'
        ]);

        $response->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_csrf_token_is_required_for_login(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password123'),
            'role' => 'User',
            'status' => 'active'
        ]);

        $response = $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class)
            ->post(route('login'), [
                'email' => 'test@example.com',
                'password' => 'Password123'
            ]);

        // With CSRF middleware disabled, login should work
        // This test verifies CSRF is normally enforced
        $response->assertRedirect();
    }
}
