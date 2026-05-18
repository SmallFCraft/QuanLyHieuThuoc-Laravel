<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'MaDM' => Category::factory(),
            'name' => $this->faker->words(3, true),
            'ingredients' => $this->faker->sentence(),
            'usage' => $this->faker->sentence(),
            'price' => $this->faker->randomFloat(2, 10000, 500000),
            'expiration_date' => $this->faker->dateTimeBetween('now', '+2 years'),
            'stock_quantity' => $this->faker->numberBetween(0, 200),
            'reorder_point' => $this->faker->numberBetween(10, 50),
            'batch_number' => $this->faker->unique()->numerify('BATCH-####'),
            'image' => null,
        ];
    }

    /**
     * Indicate that the product is out of stock.
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock_quantity' => 0,
        ]);
    }

    /**
     * Indicate that the product is expiring soon.
     */
    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'expiration_date' => $this->faker->dateTimeBetween('now', '+6 months'),
        ]);
    }

    /**
     * Indicate that the product is expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expiration_date' => $this->faker->dateTimeBetween('-1 year', '-1 day'),
        ]);
    }
}
