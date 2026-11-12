<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('danh_mucs', function (Blueprint $table) {
            $table->id('ma_dm');
            $table->string('ten')->unique();
            // Bản không dấu, không dấu phân cách của ten — đây mới là danh tính thật
            // của một danh mục. Long Châu công bố cùng một breadcrumb cấp 2 dưới nhiều
            // cách viết ("Cơ - xương - khớp" và "Cơ xương khớp"), collation
            // utf8mb4_0900_ai_ci gộp dấu thanh nhưng không gộp "đ" lẫn dấu gạch, nên
            // unique trên ten một mình không bắt được. Fold bằng
            // VietnameseText::fold(), cùng helper mà cột tìm kiếm của san_phams dùng.
            //
            // Lần gộp trùng một lần duy nhất (2026-09-01, 51 → 50 danh mục, 114 sản
            // phẩm dồn về ma_dm 6) đã chạy trên DB dev; snapshot trước khi gộp nằm ở
            // storage/backups/categories-premerge-20260901-124244.json. Bảng dựng mới
            // không cần bước đó — unique index bên dưới chặn tái diễn.
            $table->string('ten_khong_dau')->nullable();
            $table->string('ma_danh_muc', 10)->nullable();
            $table->string('bieu_tuong', 50)->nullable();
            $table->text('mo_ta')->nullable();
            $table->timestamps();

            $table->unique('ma_danh_muc', 'danh_mucs_ma_danh_muc_unique');
            $table->unique('ten_khong_dau', 'danh_mucs_ten_khong_dau_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('danh_mucs');
    }
};
