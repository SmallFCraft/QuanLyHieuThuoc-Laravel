<?php

namespace Database\Factories;

use App\Models\DanhMuc;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DanhMuc>
 */
class DanhMucFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = DanhMuc::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $categories = [
            ['ten' => 'Thuốc giảm đau', 'bieu_tuong' => 'fa-pills', 'mo_ta' => 'Các loại thuốc giảm đau, hạ sốt'],
            ['ten' => 'Vitamin & Khoáng chất', 'bieu_tuong' => 'fa-capsules', 'mo_ta' => 'Thực phẩm chức năng, vitamin'],
            ['ten' => 'Thuốc kháng sinh', 'bieu_tuong' => 'fa-syringe', 'mo_ta' => 'Các loại thuốc kháng sinh'],
            ['ten' => 'Thuốc tiêu hóa', 'bieu_tuong' => 'fa-stomach', 'mo_ta' => 'Thuốc hỗ trợ tiêu hóa'],
            ['ten' => 'Thuốc cảm cúm', 'bieu_tuong' => 'fa-head-side-cough', 'mo_ta' => 'Thuốc điều trị cảm cúm'],
        ];

        $category = $this->faker->randomElement($categories);

        return [
            'ten' => $category['ten'] . ' ' . $this->faker->unique()->numerify('###'),
            'bieu_tuong' => $category['bieu_tuong'],
            'mo_ta' => $category['mo_ta'],
        ];
    }
}
