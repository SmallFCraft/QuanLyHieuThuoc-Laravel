<?php

namespace Database\Seeders;

use App\Models\DonHang;
use App\Models\ChiTietDonHang;
use App\Models\SanPham;
use App\Models\NguoiDung;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class DonHangSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $nguoiDungs = NguoiDung::where('vai_tro', 'User')->get();
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
                'ma_nd' => $nguoiDung->ma_nd,
                'tong_tien' => 0, // Will be calculated after adding items
                'trang_thai' => $status,
                'dia_chi_giao_hang' => $addresses[array_rand($addresses)],
                'sdt_giao_hang' => $phones[array_rand($phones)],
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
                    'ma_dh' => $donHang->ma_dh,
                    'ma_sp' => $sanPham->ma_sp,
                    'ma_lo' => $lo ? $lo->ma_lo : null,
                    'so_luong' => $quantity,
                    'don_gia' => $unitPrice,
                    'so_lo' => $sanPham->so_lo,
                ]);
                
                $totalAmount += $quantity * $unitPrice;
            }
            
            // Update order total amount
            $donHang->update(['tong_tien' => $totalAmount]);
        }
        
        $this->command->info('Created 20 orders with various statuses');
    }
}
