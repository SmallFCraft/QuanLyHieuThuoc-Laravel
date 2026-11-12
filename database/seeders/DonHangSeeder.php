<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class DonHangSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $nguoiDungs = NguoiDung::where('role', 'User')->get();
        $sanPhams = SanPham::all();
        
        if ($nguoiDungs->isEmpty() || $sanPhams->isEmpty()) {
            $this->command->warn('Chưa có user hoặc sản phẩm. Chạy UserSeeder và `php artisan crawl:longchau` trước!');
            return;
        }

        $statuses = ['Pending', 'Delivering', 'Completed', 'Cancelled'];
        $addresses = [
            'Lê Văn Hiến, Ngũ Hành Sơn, Đà Nẵng',
            'Nguyễn Văn Linh, Hải Châu, Đà Nẵng',
            'Trần Phú, Sơn Trà, Đà Nẵng',
            'Võ Nguyên Giáp, Ngũ Hành Sơn, Đà Nẵng',
            'Hoàng Sa, Thanh Khê, Đà Nẵng',
        ];
        
        $phones = [
            '0901234567',
            '0912345678',
            '0923456789',
            '0934567890',
            '0945678901',
        ];

        // Create 20 orders with various statuses
        for ($i = 0; $i < 20; $i++) {
            $nguoiDung = $nguoiDungs->random();
            $status = $statuses[array_rand($statuses)];
            
            // Create order
            $donHang = DonHang::create([
                'MaKH' => $nguoiDung->MaKH,
                'total_amount' => 0, // Will be calculated after adding items
                'status' => $status,
                'shipping_address' => $addresses[array_rand($addresses)],
                'shipping_phone' => $phones[array_rand($phones)],
                'created_at' => Carbon::now()->subDays(rand(1, 30)),
            ]);

            // Add 1-5 random products to the order
            $itemCount = rand(1, 5);
            $totalAmount = 0;
            
            for ($j = 0; $j < $itemCount; $j++) {
                $sanPham = $sanPhams->random();
                $quantity = rand(1, 3);
                $unitPrice = $sanPham->price_vnd;
                
                // Get the first available batch for this product
                $lo = $sanPham->cacLo()->first();
                
                ChiTietDonHang::create([
                    'MaDH' => $donHang->MaDH,
                    'MaSP' => $sanPham->MaSP,
                    'MaLo' => $lo ? $lo->MaLo : null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'batch_number' => $sanPham->batch_number,
                ]);
                
                $totalAmount += $quantity * $unitPrice;
            }
            
            // Update order total amount
            $donHang->update(['total_amount' => $totalAmount]);
        }
        
        $this->command->info('Created 20 orders with various statuses');
    }
}
