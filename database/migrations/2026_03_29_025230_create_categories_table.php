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
            // Ban khong dau, khong dau phan cach cua ten — day moi la danh tinh that
            // cua mot danh muc. Long Chau cong bo cung mot breadcrumb cap 2 duoi nhieu
            // cach viet ("Co - xuong - khop" va "Co xuong khop"), collation
            // utf8mb4_0900_ai_ci gop dau thanh nhung khong gop "d" lan dau gach, nen
            // unique tren ten mot minh khong bat duoc. Fold bang
            // VietnameseText::fold(), cung helper ma cot tim kiem cua san_phams dung.
            //
            // Lan gop trung mot lan duy nhat (2026-09-01, 51 → 50 danh muc, 114 san
            // pham don ve ma_dm 6) da chay tren DB dev; snapshot truoc khi gop nam o
            // storage/backups/categories-premerge-20260901-124244.json. Bang dung moi
            // khong can buoc do — unique index ben duoi chan tai dien.
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
