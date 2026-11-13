<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Categories and products are no longer seeded: `php artisan crawl:longchau`
     * builds the catalog from Nha Thuoc Long Chau instead. OrderSeeder still runs
     * and no-ops until a catalog exists, so seed after crawling to get sample orders.
     */
    public function run(): void
    {
        $this->call([
            NguoiDungSeeder::class,
            DonHangSeeder::class,
            TriThucYTeSeeder::class,
        ]);
    }
}
