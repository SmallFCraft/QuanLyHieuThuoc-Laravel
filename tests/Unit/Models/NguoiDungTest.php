<?php

namespace Tests\Unit\Models;

use App\Models\NguoiDung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NguoiDungTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_provider_dung_model_nguoi_dung(): void
    {
        $nguoiDung = NguoiDung::factory()->create([
            'email' => 'kiemtra@load99',
            'role' => 'Administrator',
        ]);

        $this->assertSame(NguoiDung::class, config('auth.providers.users.model'));
        $this->assertTrue($nguoiDung->isAdministrator());
    }
}
