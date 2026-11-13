<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chuyển dữ liệu đang chạy sang schema tiếng Việt.
     *
     * LƯU Ý MYSQL: SQLite chấp nhận renameColumn trực tiếp. Trên MySQL, nếu cột
     * đang được foreign key trỏ tới (vd nguoi_dungs.ma_nd), phải dropForeign()
     * trước, renameColumn, rồi tạo lại foreign key sau. Bảng có FK trỏ ma_nd:
     * sessions (user_id), don_hangs, chi_tiet_don_hangs, lo_san_phams, san_phams,
     * yeu_thiches, anh_chatbots, tin_nhan_chats, nhat_ky_kiem_toans, ho_so_suc_khoes,
     * phien_sang_locs, nhac_uong_thuocs, ai_jobs, quy_tac_phan_tang_y_tes (reviewed_by).
     */
    private array $bang = [
        'users' => 'nguoi_dungs',
        'categories' => 'danh_mucs',
        'products' => 'san_phams',
        'product_batches' => 'lo_san_phams',
        'orders' => 'don_hangs',
        'order_items' => 'chi_tiet_don_hangs',
        'audit_logs' => 'nhat_ky_kiem_toans',
        'contact_inquiries' => 'yeu_cau_lien_hes',
        'chatbot_images' => 'anh_chatbots',
        'chat_messages' => 'tin_nhan_chats',
        'favorites' => 'yeu_thiches',
        'medical_symptoms' => 'trieu_chung_y_tes',
        'medical_red_flags' => 'co_do_y_tes',
        'medical_triage_rules' => 'quy_tac_phan_tang_y_tes',
        'drug_interactions' => 'tuong_tac_thuocs',
        'drug_contraindications' => 'chung_chi_dinh_thuocs',
        'user_health_profiles' => 'ho_so_suc_khoes',
        'triage_sessions' => 'phien_sang_locs',
        'medication_reminders' => 'nhac_uong_thuocs',
    ];

    /** @var array<string, array<string, string>> bảng => [cột cũ => cột mới] */
    private array $map = [
        'nguoi_dungs' => [
            'MaKH' => 'ma_nd',
            'ma_nd' => 'ma_nd',
            'name' => 'ho_ten',
            'email_verified_at' => 'thoi_diem_xac_thuc_email',
            'birth_date' => 'ngay_sinh',
            'gender' => 'gioi_tinh',
            'address' => 'dia_chi',
            'phone' => 'so_dien_thoai',
            'avatar' => 'anh_dai_dien',
            'role' => 'vai_tro',
            'status' => 'trang_thai',
        ],
        'danh_mucs' => [
            'MaDM' => 'ma_dm',
            'ma_dm' => 'ma_dm',
            'name' => 'ten',
            'name_folded' => 'ten_khong_dau',
            'code' => 'ma_danh_muc',
            'icon' => 'bieu_tuong',
            'description' => 'mo_ta',
        ],
        'san_phams' => [
            'MaSP' => 'ma_sp',
            'MaDM' => 'ma_dm',
            'ma_sp' => 'ma_sp',
            'product_code' => 'ma_san_pham',
            'ma_dm' => 'ma_dm',
            'name' => 'ten',
            'name_folded' => 'ten_khong_dau',
            'ingredients' => 'thanh_phan',
            'ingredients_folded' => 'thanh_phan_khong_dau',
            'usage' => 'cach_dung',
            'shelf_life_months' => 'han_dung_thang',
            'price' => 'gia',
            'expiration_date' => 'ngay_het_han',
            'stock_quantity' => 'so_luong_ton',
            'reorder_point' => 'muc_canh_bao',
            'batch_number' => 'so_lo',
            'image' => 'hinh_anh',
            'views_count' => 'luot_xem',
        ],
        'lo_san_phams' => [
            'MaLo' => 'ma_lo',
            'MaSP' => 'ma_sp',
            'ma_lo' => 'ma_lo',
            'ma_sp' => 'ma_sp',
            'batch_number' => 'so_lo',
            'expiration_date' => 'ngay_het_han',
            'stock_quantity' => 'so_luong_ton',
            'reorder_point' => 'muc_canh_bao',
        ],
        'don_hangs' => [
            'MaDH' => 'ma_dh',
            'MaKH' => 'ma_nd',
            'ma_dh' => 'ma_dh',
            'ma_nd' => 'ma_nd',
            'total_amount' => 'tong_tien',
            'status' => 'trang_thai',
            'shipping_address' => 'dia_chi_giao_hang',
            'shipping_phone' => 'sdt_giao_hang',
            'delivery_time' => 'thoi_gian_giao',
            'order_notes' => 'ghi_chu',
        ],
        'chi_tiet_don_hangs' => [
            'MaCTDH' => 'ma_ctdh',
            'MaDH' => 'ma_dh',
            'MaSP' => 'ma_sp',
            'MaLo' => 'ma_lo',
            'ma_ctdh' => 'ma_ctdh',
            'ma_dh' => 'ma_dh',
            'ma_sp' => 'ma_sp',
            'ma_lo' => 'ma_lo',
            'quantity' => 'so_luong',
            'unit_price' => 'don_gia',
            'batch_number' => 'so_lo',
        ],
        'nhat_ky_kiem_toans' => [
            'MaNK' => 'ma_nk',
            'MaKH' => 'ma_nd',
            'ma_nk' => 'ma_nk',
            'ma_nd' => 'ma_nd',
        ],
        'yeu_cau_lien_hes' => [
            'MaLH' => 'ma_lh',
        ],
        'anh_chatbots' => [
            'MaAnh' => 'ma_anh',
            'MaKH' => 'ma_nd',
            'ma_anh' => 'ma_anh',
            'ma_nd' => 'ma_nd',
        ],
        'tin_nhan_chats' => [
            'MaTN' => 'ma_tn',
            'MaKH' => 'ma_nd',
            'ma_tn' => 'ma_tn',
            'ma_nd' => 'ma_nd',
        ],
        'yeu_thiches' => [
            'MaYT' => 'ma_yt',
            'MaKH' => 'ma_nd',
            'MaSP' => 'ma_sp',
            'ma_yt' => 'ma_yt',
            'ma_nd' => 'ma_nd',
            'ma_sp' => 'ma_sp',
        ],
        'trieu_chung_y_tes' => [
            'MaTC' => 'ma_tc',
            'ma_tc' => 'ma_tc',
        ],
        'co_do_y_tes' => [
            'MaCB' => 'ma_cb',
            'ma_cb' => 'ma_cb',
        ],
        'quy_tac_phan_tang_y_tes' => [
            'MaQT' => 'ma_qt',
            'ma_qt' => 'ma_qt',
            'ma_tc' => 'ma_tc',
        ],
        'tuong_tac_thuocs' => [
            'MaTT' => 'ma_tt',
            'ma_tt' => 'ma_tt',
        ],
        'chung_chi_dinh_thuocs' => [
            'MaCCD' => 'ma_ccd',
            'ma_ccd' => 'ma_ccd',
        ],
        'ho_so_suc_khoes' => [
            'MaHS' => 'ma_hs',
            'MaKH' => 'ma_nd',
            'ma_hs' => 'ma_hs',
            'ma_nd' => 'ma_nd',
        ],
        'phien_sang_locs' => [
            'MaSL' => 'ma_sl',
            'MaKH' => 'ma_nd',
            'MaNN' => 'ma_nn',
            'MaDH' => 'ma_dh',
            'MaSP' => 'ma_sp',
            'ma_sl' => 'ma_sl',
            'ma_nd' => 'ma_nd',
        ],
        'nhac_uong_thuocs' => [
            'MaNN' => 'ma_nn',
            'MaKH' => 'ma_nd',
            'ma_nn' => 'ma_nn',
            'ma_nd' => 'ma_nd',
            'ma_dh' => 'ma_dh',
            'ma_sp' => 'ma_sp',
        ],
        'ai_jobs' => [
            'MaAJ' => 'ma_aj',
            'MaKH' => 'ma_nd',
            'ma_aj' => 'ma_aj',
            'ma_nd' => 'ma_nd',
        ],
    ];

    public function up(): void
    {
        foreach ($this->bang as $cu => $moi) {
            if (Schema::hasTable($cu) && ! Schema::hasTable($moi)) {
                Schema::rename($cu, $moi);
            }
        }

        foreach ($this->map as $cu => $cots) {
            if (! Schema::hasTable($cu)) {
                continue;
            }

            $conLai = array_filter(
                $cots,
                fn (string $moi, string $tenCu): bool => Schema::hasColumn($cu, $tenCu)
                    && ! Schema::hasColumn($cu, $moi),
                ARRAY_FILTER_USE_BOTH
            );

            if ($conLai === []) {
                continue;
            }

            Schema::table($cu, function (Blueprint $table) use ($conLai): void {
                foreach ($conLai as $tenCu => $moi) {
                    $table->renameColumn($tenCu, $moi);
                }
            });
        }
    }

    public function down(): void
    {
        $cu = array_flip($this->bang);

        foreach ($this->map as $moi => $cots) {
            if (! Schema::hasTable($moi)) {
                continue;
            }

            $conLai = array_filter(
                $cots,
                fn (string $tenCu, string $tenMoi): bool => Schema::hasColumn($moi, $tenMoi)
                    && ! Schema::hasColumn($moi, $tenCu),
                ARRAY_FILTER_USE_BOTH
            );

            if ($conLai === []) {
                continue;
            }

            Schema::table($moi, function (Blueprint $table) use ($conLai): void {
                foreach ($conLai as $tenMoi => $tenCu) {
                    $table->renameColumn($tenMoi, $tenCu);
                }
            });
        }

        foreach ($this->bang as $cu => $moi) {
            if (Schema::hasTable($moi) && ! Schema::hasTable($cu)) {
                Schema::rename($moi, $cu);
            }
        }
    }
};
