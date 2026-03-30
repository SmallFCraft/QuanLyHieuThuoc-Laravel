# Database Seeders

This directory contains database seeders for populating the Pharmacy Management System with realistic Vietnamese development data.

## Available Seeders

### 1. UserSeeder
Creates user accounts for testing:
- **Admin Account**: 
  - Email: `admin@load99`
  - Password: `Gunny123456@`
  - Role: Administrator
  - Có sẵn: ngày sinh, giới tính, địa chỉ, SĐT (xem `UserSeeder`)
- **5 Sample Users** (customers):
  - Nguyễn Văn An (nguyenvanan@example.com)
  - Trần Thị Bình (tranthibinh@example.com)
  - Lê Hoàng Cường (lehoangcuong@example.com)
  - Phạm Thị Dung (phamthidung@example.com)
  - Hoàng Văn Em (hoangvanem@example.com)
  - Password for all: `User123`
  - Mỗi tài khoản có đủ hồ sơ: `birth_date`, `gender`, `address`, `phone`, email đã xác thực

### 2. CategorySeeder
Creates 5 Vietnamese pharmaceutical categories:
1. Thuốc giảm đau - Hạ sốt (Pain relievers - Fever reducers)
2. Kháng sinh (Antibiotics)
3. Vitamin và khoáng chất (Vitamins and minerals)
4. Thuốc tiêu hóa (Digestive medicines)
5. Thuốc tim mạch (Cardiovascular medicines)

### 3. ProductSeeder
Creates 50 realistic Vietnamese pharmaceutical products:
- **10 products per category**
- All products have:
  - Vietnamese names and descriptions
  - Realistic ingredients and usage instructions
  - Future expiration dates (15-24 months from now)
  - Unique batch numbers (e.g., PAR-2024-001, IBU-2024-001)
  - Appropriate stock quantities and reorder points
  - Realistic Vietnamese pricing (in VND)

**Sample products include:**
- Paracetamol 500mg
- Ibuprofen 400mg
- Amoxicillin 500mg
- Vitamin C 1000mg
- Omeprazole 20mg
- Amlodipine 5mg
- And 44 more...

### 4. OrderSeeder
Creates 20 orders with various statuses:
- **Pending**: ~6 orders
- **Delivering**: ~5 orders
- **Completed**: ~6 orders
- **Cancelled**: ~3 orders

Each order includes:
- Random customer assignment
- 1-5 random products
- Vietnamese shipping addresses
- Vietnamese phone numbers
- Created dates within the last 30 days

## Usage

### Run All Seeders
```bash
php artisan db:seed
```

### Run Specific Seeder
```bash
php artisan db:seed --class=UserSeeder
php artisan db:seed --class=CategorySeeder
php artisan db:seed --class=ProductSeeder
php artisan db:seed --class=OrderSeeder
```

### Fresh Migration with Seeding
```bash
php artisan migrate:fresh --seed
```

## Data Validation

All seeded data follows these rules:
- ✓ All expiration dates are in the future (15-24 months)
- ✓ All batch numbers are unique
- ✓ All prices are non-negative
- ✓ All stock quantities are non-negative
- ✓ All Vietnamese text uses proper UTF-8 encoding
- ✓ Order statuses follow valid transitions
- ✓ All foreign key relationships are valid

## Development Notes

- The seeders are designed for **development and testing only**
- Do not run these seeders in production
- All passwords are hashed using Laravel's Hash facade
- The admin password should be changed immediately in production
- Sample user passwords are intentionally simple for testing

## Customization

To modify the seeded data:
1. Edit the respective seeder file in `database/seeders/`
2. Run `php artisan db:seed --class=YourSeederClass`
3. Or run `php artisan migrate:fresh --seed` to reset everything

## Verification

To verify the seeded data:
```bash
php artisan tinker
>>> App\Models\User::count()
>>> App\Models\Category::count()
>>> App\Models\Product::count()
>>> App\Models\Order::count()
```

Expected counts:
- Users: 6 (1 admin + 5 customers)
- Categories: 5
- Products: 50
- Orders: 20
