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
                'description' => 'Các loại thuốc giảm đau, hạ sốt, chống viêm không steroid',
            ],
            [
                'name' => 'Kháng sinh',
                'description' => 'Thuốc kháng sinh điều trị nhiễm khuẩn',
            ],
            [
                'name' => 'Vitamin và khoáng chất',
                'description' => 'Thực phẩm chức năng, vitamin, khoáng chất bổ sung',
            ],
            [
                'name' => 'Thuốc tiêu hóa',
                'description' => 'Thuốc điều trị các bệnh về đường tiêu hóa',
            ],
            [
                'name' => 'Thuốc tim mạch',
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
