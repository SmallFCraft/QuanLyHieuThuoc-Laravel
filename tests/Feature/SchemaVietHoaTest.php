<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaVietHoaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cac_bang_va_cot_da_chuyen_sang_tieng_viet(): void
    {
        foreach (['san_phams', 'danh_mucs', 'don_hangs', 'chi_tiet_don_hangs',
                  'lo_san_phams', 'nguoi_dungs', 'ho_so_suc_khoes', 'yeu_thiches',
                  'tin_nhan_chats', 'anh_chatbots', 'yeu_cau_lien_hes',
                  'tuong_tac_thuocs', 'chung_chi_dinh_thuocs', 'trieu_chung_y_tes',
                  'co_do_y_tes', 'quy_tac_phan_tang_y_tes', 'phien_sang_locs',
                  'nhac_uong_thuocs', 'phien_am_giong_nois', 'truy_van_tim_kiems',
                  'nhat_ky_kiem_toans', 'ai_jobs'] as $bang) {
            $this->assertTrue(Schema::hasTable($bang), "Thiếu bảng {$bang}");
        }

        foreach ([
            ['san_phams', ['ma_sp', 'ma_san_pham', 'ma_dm', 'ten', 'ten_khong_dau',
                           'gia', 'so_luong_ton', 'ngay_het_han', 'muc_canh_bao',
                           'so_lo', 'hinh_anh', 'luot_xem', 'thanh_phan', 'cach_dung']],
            ['nguoi_dungs', ['ma_nd', 'ho_ten', 'email', 'thoi_diem_xac_thuc_email',
                             'ngay_sinh', 'gioi_tinh', 'dia_chi', 'so_dien_thoai',
                             'anh_dai_dien', 'vai_tro', 'trang_thai', 'remember_token']],
            ['don_hangs', ['ma_dh', 'ma_nd', 'tong_tien', 'trang_thai',
                           'dia_chi_giao_hang', 'sdt_giao_hang', 'thoi_gian_giao', 'ghi_chu']],
            ['lo_san_phams', ['ma_lo', 'ma_sp', 'so_lo', 'ngay_het_han',
                              'so_luong_ton', 'muc_canh_bao']],
            ['chi_tiet_don_hangs', ['ma_ctdh', 'ma_dh', 'ma_sp', 'ma_lo',
                                    'so_luong', 'don_gia']],
        ] as [$bang, $cots]) {
            foreach ($cots as $cot) {
                $this->assertTrue(Schema::hasColumn($bang, $cot), "Thiếu {$bang}.{$cot}");
            }
        }
    }

    public function test_bang_framework_giu_nguyen(): void
    {
        foreach (['sessions', 'jobs', 'job_batches', 'failed_jobs',
                  'cache', 'cache_locks', 'password_reset_tokens'] as $bang) {
            $this->assertTrue(Schema::hasTable($bang), "Bảng framework {$bang} bị mất");
        }
    }

    public function test_khoa_ngoai_sessions_tro_ve_nguoi_dungs(): void
    {
        $this->assertTrue(
            Schema::hasColumn('sessions', 'user_id'),
            'Session dùng cột user_id; giá trị phải trỏ ma_nd của nguoi_dungs'
        );
    }
}
