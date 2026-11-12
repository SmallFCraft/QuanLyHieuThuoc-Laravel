<?php

namespace Database\Seeders;

use App\Models\NguoiDung;
use Illuminate\Database\Seeder;

class NguoiDungSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Tài khoản quản trị (đăng nhập: admin@load99 / Gunny123456@)
        NguoiDung::updateOrCreate(
            ['email' => 'admin@load99'],
            [
                'ho_ten' => 'Quản trị viên',
                'password' => 'Gunny123456@',
                'ngay_sinh' => '1985-06-15',
                'gioi_tinh' => 'male',
                'dia_chi' => '123 Đường Y Dược, Phường 1, Quận 1, TP. Đà Nẵng',
                'so_dien_thoai' => '0909123456',
                'vai_tro' => 'Administrator',
                'trang_thai' => 'active',
                'thoi_diem_xac_thuc_email' => now(),
            ]
        );

        // Dược sĩ phụ trách hàng đợi sàng lọc (đăng nhập: duocsi@load99 / Gunny123456@)
        NguoiDung::updateOrCreate(
            ['email' => 'duocsi@load99'],
            [
                'ho_ten' => 'Dược sĩ Nguyễn Thị Hạnh',
                'password' => 'Gunny123456@',
                'ngay_sinh' => '1990-04-22',
                'gioi_tinh' => 'female',
                'dia_chi' => '123 Đường Y Dược, Phường 1, Quận 1, TP. Đà Nẵng',
                'so_dien_thoai' => '0909887766',
                'vai_tro' => 'Pharmacist',
                'trang_thai' => 'active',
                'thoi_diem_xac_thuc_email' => now(),
            ]
        );

        $nguoiDungs = [
            [
                'ho_ten' => 'Nguyễn Văn An',
                'email' => 'nguyenvanan@example.com',
                'password' => 'User123',
                'ngay_sinh' => '1992-03-20',
                'gioi_tinh' => 'male',
                'dia_chi' => 'Lê Văn Hiến, Ngũ Hành Sơn, Đà Nẵng',
                'so_dien_thoai' => '0901111222',
                'vai_tro' => 'User',
                'trang_thai' => 'active',
                'thoi_diem_xac_thuc_email' => now(),
            ],
            [
                'ho_ten' => 'Trần Thị Bình',
                'email' => 'tranthibinh@example.com',
                'password' => 'User123',
                'ngay_sinh' => '1995-11-08',
                'gioi_tinh' => 'female',
                'dia_chi' => '78 Lê Lợi, Phường Hải Châu, Quận Hải Châu, Đà Nẵng',
                'so_dien_thoai' => '0912222333',
                'vai_tro' => 'User',
                'trang_thai' => 'active',
                'thoi_diem_xac_thuc_email' => now(),
            ],
            [
                'ho_ten' => 'Lê Hoàng Cường',
                'email' => 'lehoangcuong@example.com',
                'password' => 'User123',
                'ngay_sinh' => '1988-07-01',
                'gioi_tinh' => 'male',
                'dia_chi' => '12 Trần Hưng Đạo, Phường Lý Thường Kiệt, TP. Hà Nội',
                'so_dien_thoai' => '0933333444',
                'vai_tro' => 'User',
                'trang_thai' => 'active',
                'thoi_diem_xac_thuc_email' => now(),
            ],
            [
                'ho_ten' => 'Phạm Thị Dung',
                'email' => 'phamthidung@example.com',
                'password' => 'User123',
                'ngay_sinh' => '2000-01-25',
                'gioi_tinh' => 'female',
                'dia_chi' => '56 Nguyễn Văn Linh, Phường Tân Thuận, Quận 7, TP. Hồ Chí Minh',
                'so_dien_thoai' => '0944444555',
                'vai_tro' => 'User',
                'trang_thai' => 'active',
                'thoi_diem_xac_thuc_email' => now(),
            ],
            [
                'ho_ten' => 'Hoàng Văn Em',
                'email' => 'hoangvanem@example.com',
                'password' => 'User123',
                'ngay_sinh' => '1997-12-12',
                'gioi_tinh' => 'other',
                'dia_chi' => '9 Điện Biên Phủ, Phường Đakao, Quận 1, TP. Hồ Chí Minh',
                'so_dien_thoai' => '0966666777',
                'vai_tro' => 'User',
                'trang_thai' => 'active',
                'thoi_diem_xac_thuc_email' => now(),
            ],
        ];

        foreach ($nguoiDungs as $duLieuNguoiDung) {
            NguoiDung::updateOrCreate(
                ['email' => $duLieuNguoiDung['email']],
                $duLieuNguoiDung
            );
        }
    }
}
