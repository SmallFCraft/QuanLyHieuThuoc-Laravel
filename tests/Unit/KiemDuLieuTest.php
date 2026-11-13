<?php

namespace Tests\Unit;

use App\Models\NguoiDung;
use App\Models\DanhMuc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class KiemDuLieuTest extends TestCase
{
    use RefreshDatabase;

    public function test_dinh_dang_email(): void
    {
        $validEmails = [
            'test@example.com',
            'user.name@domain.co.vn',
            'admin+tag@pharmacy.com'
        ];

        $invalidEmails = [
            'invalid-email',
            '@example.com',
            'user@',
            'user name@example.com'
        ];

        foreach ($validEmails as $email) {
            $validator = Validator::make(
                ['email' => $email],
                ['email' => 'required|email']
            );
            $this->assertFalse($validator->fails(), "Valid email {$email} failed validation");
        }

        foreach ($invalidEmails as $email) {
            $validator = Validator::make(
                ['email' => $email],
                ['email' => 'required|email']
            );
            $this->assertTrue($validator->fails(), "Invalid email {$email} passed validation");
        }
    }

    public function test_email_doc_nhat(): void
    {
        NguoiDung::factory()->create(['email' => 'existing@example.com'
        ]);

        $validator = Validator::make(
            ['email' => 'existing@example.com'],
            ['email' => 'required|email|unique:nguoi_dungs,email']
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
    }

    public function test_dinh_dang_so_dien_thoai_viet_nam(): void
    {
        $validPhones = [
            '0901234567',
            '0912345678',
            '+84901234567',
            '+84912345678'
        ];

        $invalidPhones = [
            '123456',
            'abcdefghij',
            '090123456',  // Too short
            '09012345678', // Too long
            '1234567890'  // Doesn't start with 0 or +84
        ];

        $phoneRule = ['required', 'regex:/^(\+84|0)[0-9]{9}$/'];

        foreach ($validPhones as $phone) {
            $validator = Validator::make(
                ['phone' => $phone],
                ['phone' => $phoneRule]
            );
            $this->assertFalse($validator->fails(), "Valid phone {$phone} failed validation");
        }

        foreach ($invalidPhones as $phone) {
            $validator = Validator::make(
                ['phone' => $phone],
                ['phone' => $phoneRule]
            );
            $this->assertTrue($validator->fails(), "Invalid phone {$phone} passed validation");
        }
    }

    public function test_yeu_cau_mat_khau(): void
    {
        $validPasswords = [
            'Password123',
            'Admin@2024',
            'SecureP@ss1'
        ];

        $invalidPasswords = [
            'password',      // No uppercase, no numbers
            'PASSWORD123',   // No lowercase
            'Password',      // No numbers
            'Pass1',         // Too short
        ];

        $passwordRule = 'required|string|min:8|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/';

        foreach ($validPasswords as $password) {
            $validator = Validator::make(
                ['password' => $password],
                ['password' => $passwordRule]
            );
            $this->assertFalse($validator->fails(), "Valid password failed validation");
        }

        foreach ($invalidPasswords as $password) {
            $validator = Validator::make(
                ['password' => $password],
                ['password' => $passwordRule]
            );
            $this->assertTrue($validator->fails(), "Invalid password passed validation");
        }
    }

    public function test_dinh_dang_ngay(): void
    {
        $validDates = [
            '2024-12-31',
            '2025-01-01',
            now()->addYear()->format('Y-m-d')
        ];

        $invalidDates = [
            '31/12/2024',  // Wrong format
            '2024-13-01',  // Invalid month
            '2024-12-32',  // Invalid day
            'not-a-date'
        ];

        foreach ($validDates as $date) {
            $validator = Validator::make(
                ['date' => $date],
                ['date' => 'required|date|date_format:Y-m-d']
            );
            $this->assertFalse($validator->fails(), "Valid date {$date} failed validation");
        }

        foreach ($invalidDates as $date) {
            $validator = Validator::make(
                ['date' => $date],
                ['date' => 'required|date|date_format:Y-m-d']
            );
            $this->assertTrue($validator->fails(), "Invalid date {$date} passed validation");
        }
    }

    public function test_dinh_dang_tien_te(): void
    {
        $validPrices = [
            '10000',
            '50000.50',
            '999999.99',
            '0'
        ];

        $invalidPrices = [
            '-1000',      // Negative
            'abc',        // Not numeric
            '10000.999',  // More than 2 decimals
        ];

        foreach ($validPrices as $price) {
            $validator = Validator::make(
                ['price' => $price],
                ['price' => 'required|numeric|min:0|regex:/^\d+(\.\d{1,2})?$/']
            );
            $this->assertFalse($validator->fails(), "Valid price {$price} failed validation");
        }

        foreach ($invalidPrices as $price) {
            $validator = Validator::make(
                ['price' => $price],
                ['price' => 'required|numeric|min:0|regex:/^\d+(\.\d{1,2})?$/']
            );
            $this->assertTrue($validator->fails(), "Invalid price {$price} passed validation");
        }
    }

    public function test_quy_tac_kiem_du_lieu_san_pham(): void
    {
        $danhMuc = DanhMuc::create(['ten' => 'Test Category',
            'mo_ta' => 'Test Description'
        ]);

        $validData = [
            'ma_dm' => $danhMuc->ma_dm,
            'name' => 'Test Medicine',
            'price' => 50000,
            'expiration_date' => now()->addYear()->format('Y-m-d'),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-001'
        ];

        $validator = Validator::make($validData, [
            'ma_dm' => 'required|exists:danh_mucs,ma_dm',
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'expiration_date' => 'required|date|after:today',
            'stock_quantity' => 'required|integer|min:0',
            'reorder_point' => 'required|integer|min:0',
            'batch_number' => 'required|string|max:100'
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_quy_tac_kiem_du_lieu_don_hang(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $validData = [
            'ma_nd' => $nguoiDung->ma_nd,
            'total_amount' => 100000,
            'status' => 'Pending',
            'shipping_address' => '123 Test Street, Test City',
            'shipping_phone' => '0901234567'
        ];

        $validator = Validator::make($validData, [
            'ma_nd' => 'required|exists:nguoi_dungs,ma_nd',
            'total_amount' => 'required|numeric|min:0',
            'status' => 'required|in:Pending,Delivering,Completed,Cancelled',
            'shipping_address' => 'required|string',
            'shipping_phone' => ['required', 'regex:/^(\+84|0)[0-9]{9}$/']
        ]);

        $this->assertFalse($validator->fails());
    }

    public function test_kiem_du_lieu_phia_server_chan_xss(): void
    {
        $xssAttempts = [
            '<script>alert("XSS")</script>',
            '<img src=x onerror=alert("XSS")>',
            'javascript:alert("XSS")'
        ];

        foreach ($xssAttempts as $xss) {
            $validator = Validator::make(
                ['name' => $xss],
                ['name' => 'required|string|max:255']
            );

            // Validation should pass (it's a string), but Laravel will escape it on output
            $this->assertFalse($validator->fails());
            
            // Verify that e() function escapes the string
            $escaped = e($xss);
            $this->assertStringNotContainsString('<script>', $escaped);
        }
    }
}
