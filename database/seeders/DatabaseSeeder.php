<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Categories and products are no longer seeded: `php artisan crawl:longchau`
     * builds the catalog from Nhà Thuốc Long Châu instead. OrderSeeder still runs
     * and no-ops until a catalog exists, so seed after crawling to get sample orders.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            OrderSeeder::class,
            MedicalKnowledgeSeeder::class,
        ]);
    }
}
