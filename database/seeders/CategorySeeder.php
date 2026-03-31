<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Thuốc giảm đau - Hạ sốt',
                'icon' => 'thermometer-half',
                'description' => 'Các loại thuốc giảm đau, hạ sốt, chống viêm không steroid',
            ],
            [
                'name' => 'Kháng sinh',
                'icon' => 'shield-fill-check',
                'description' => 'Thuốc kháng sinh điều trị nhiễm khuẩn',
            ],
            [
                'name' => 'Vitamin và khoáng chất',
                'icon' => 'capsule',
                'description' => 'Thực phẩm chức năng, vitamin, khoáng chất bổ sung',
            ],
            [
                'name' => 'Thuốc tiêu hóa',
                'icon' => 'droplet-fill',
                'description' => 'Thuốc điều trị các bệnh về đường tiêu hóa',
            ],
            [
                'name' => 'Thuốc tim mạch',
                'icon' => 'heart-pulse-fill',
                'description' => 'Thuốc điều trị các bệnh về tim mạch và huyết áp',
            ],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                ['name' => $category['name']],
                $category
            );
        }
    }
}
