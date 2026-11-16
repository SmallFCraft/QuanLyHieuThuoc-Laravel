<?php

namespace Database\Factories;

use App\Models\NguoiDung;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NguoiDung>
 */
class NguoiDungFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ho_ten' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'thoi_diem_xac_thuc_email' => now(),
            'ngay_sinh' => fake()->optional()->date(),
            'gioi_tinh' => fake()->optional()->randomElement(['male', 'female', 'other']),
            'dia_chi' => fake()->optional()->streetAddress(),
            'so_dien_thoai' => fake()->optional()->numerify('09########'),
            'password' => static::$password ??= Hash::make('password'),
            'vai_tro' => 'User',
            'trang_thai' => 'active',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'thoi_diem_xac_thuc_email' => null,
        ]);
    }
}
