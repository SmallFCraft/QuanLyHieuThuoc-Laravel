<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Category::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $categories = [
            ['name' => 'Thuốc giảm đau', 'icon' => 'fa-pills', 'description' => 'Các loại thuốc giảm đau, hạ sốt'],
            ['name' => 'Vitamin & Khoáng chất', 'icon' => 'fa-capsules', 'description' => 'Thực phẩm chức năng, vitamin'],
            ['name' => 'Thuốc kháng sinh', 'icon' => 'fa-syringe', 'description' => 'Các loại thuốc kháng sinh'],
            ['name' => 'Thuốc tiêu hóa', 'icon' => 'fa-stomach', 'description' => 'Thuốc hỗ trợ tiêu hóa'],
            ['name' => 'Thuốc cảm cúm', 'icon' => 'fa-head-side-cough', 'description' => 'Thuốc điều trị cảm cúm'],
        ];

        $category = $this->faker->randomElement($categories);

        return [
            'name' => $category['name'],
            'icon' => $category['icon'],
            'description' => $category['description'],
        ];
    }
}
