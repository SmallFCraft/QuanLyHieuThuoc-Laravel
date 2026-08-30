<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Tài khoản quản trị (đăng nhập: admin@load99 / Gunny123456@)
        User::updateOrCreate(
            ['email' => 'admin@load99'],
            [
                'name' => 'Quản trị viên',
                'password' => 'Gunny123456@',
                'birth_date' => '1985-06-15',
                'gender' => 'male',
                'address' => '123 Đường Y Dược, Phường 1, Quận 1, TP. Đà Nẵng',
                'phone' => '0909123456',
                'role' => 'Administrator',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        // Dược sĩ phụ trách hàng đợi sàng lọc (đăng nhập: duocsi@load99 / Gunny123456@)
        User::updateOrCreate(
            ['email' => 'duocsi@load99'],
            [
                'name' => 'Dược sĩ Nguyễn Thị Hạnh',
                'password' => 'Gunny123456@',
                'birth_date' => '1990-04-22',
                'gender' => 'female',
                'address' => '123 Đường Y Dược, Phường 1, Quận 1, TP. Đà Nẵng',
                'phone' => '0909887766',
                'role' => 'Pharmacist',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $users = [
            [
                'name' => 'Nguyễn Văn An',
                'email' => 'nguyenvanan@example.com',
                'password' => 'User123',
                'birth_date' => '1992-03-20',
                'gender' => 'male',
                'address' => 'Lê Văn Hiến, Ngũ Hành Sơn, Đà Nẵng',
                'phone' => '0901111222',
                'role' => 'User',
                'status' => 'active',
                'email_verified_at' => now(),
            ],
            [
                'name' => 'Trần Thị Bình',
                'email' => 'tranthibinh@example.com',
                'password' => 'User123',
                'birth_date' => '1995-11-08',
                'gender' => 'female',
                'address' => '78 Lê Lợi, Phường Hải Châu, Quận Hải Châu, Đà Nẵng',
                'phone' => '0912222333',
                'role' => 'User',
                'status' => 'active',
                'email_verified_at' => now(),
            ],
            [
                'name' => 'Lê Hoàng Cường',
                'email' => 'lehoangcuong@example.com',
                'password' => 'User123',
                'birth_date' => '1988-07-01',
                'gender' => 'male',
                'address' => '12 Trần Hưng Đạo, Phường Lý Thường Kiệt, TP. Hà Nội',
                'phone' => '0933333444',
                'role' => 'User',
                'status' => 'active',
                'email_verified_at' => now(),
            ],
            [
                'name' => 'Phạm Thị Dung',
                'email' => 'phamthidung@example.com',
                'password' => 'User123',
                'birth_date' => '2000-01-25',
                'gender' => 'female',
                'address' => '56 Nguyễn Văn Linh, Phường Tân Thuận, Quận 7, TP. Hồ Chí Minh',
                'phone' => '0944444555',
                'role' => 'User',
                'status' => 'active',
                'email_verified_at' => now(),
            ],
            [
                'name' => 'Hoàng Văn Em',
                'email' => 'hoangvanem@example.com',
                'password' => 'User123',
                'birth_date' => '1997-12-12',
                'gender' => 'other',
                'address' => '9 Điện Biên Phủ, Phường Đakao, Quận 1, TP. Hồ Chí Minh',
                'phone' => '0966666777',
                'role' => 'User',
                'status' => 'active',
                'email_verified_at' => now(),
            ],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                $userData
            );
        }
    }
}
