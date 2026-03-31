<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::where('role', 'User')->get();
        $products = Product::all();
        
        if ($users->isEmpty() || $products->isEmpty()) {
            $this->command->warn('Please run UserSeeder and ProductSeeder first!');
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
            $user = $users->random();
            $status = $statuses[array_rand($statuses)];
            
            // Create order
            $order = Order::create([
                'user_id' => $user->id,
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
                $product = $products->random();
                $quantity = rand(1, 3);
                $unitPrice = $product->price;
                
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'batch_number' => $product->batch_number,
                ]);
                
                $totalAmount += $quantity * $unitPrice;
            }
            
            // Update order total amount
            $order->update(['total_amount' => $totalAmount]);
        }
        
        $this->command->info('Created 20 orders with various statuses');
    }
}
