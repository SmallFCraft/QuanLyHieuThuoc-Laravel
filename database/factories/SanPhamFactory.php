<?php

namespace Database\Factories;

use App\Models\SanPham;
use App\Models\DanhMuc;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SanPham>
 */
class SanPhamFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = SanPham::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ma_dm' => DanhMuc::factory(),
            'ten' => $this->faker->words(3, true),
            'thanh_phan' => $this->faker->sentence(),
            'cach_dung' => $this->faker->sentence(),
            'gia' => $this->faker->randomFloat(2, 10000, 500000),
            'ngay_het_han' => $this->faker->dateTimeBetween('now', '+2 years'),
            'so_luong_ton' => $this->faker->numberBetween(0, 200),
            'muc_canh_bao' => $this->faker->numberBetween(10, 50),
            'so_lo' => $this->faker->unique()->numerify('BATCH-####'),
            'hinh_anh' => null,
        ];
    }

    /**
     * Indicate that the product is out of stock.
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'so_luong_ton' => 0,
        ]);
    }

    /**
     * Indicate that the product is expiring soon.
     */
    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'ngay_het_han' => $this->faker->dateTimeBetween('now', '+6 months'),
        ]);
    }

    /**
     * Indicate that the product is expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'ngay_het_han' => $this->faker->dateTimeBetween('-1 year', '-1 day'),
        ]);
    }
}
