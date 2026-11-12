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
        Schema::create('san_phams', function (Blueprint $table) {
            $table->id('ma_sp');
            $table->string('ma_san_pham', 16)->nullable();
            $table->foreignId('ma_dm')->constrained('danh_mucs', 'ma_dm')->onDelete('restrict');
            $table->string('ten');
            // Bản không dấu của các trường khách hàng tìm theo tên. Collation
            // utf8mb4_0900_ai_ci gộp dấu thanh — "á" khớp "a" — nhưng không gộp "đ",
            // nên khách gõ "dau dau" không ra gì còn "đau đầu" ra 30 sản phẩm. Fold
            // thực hiện trong PHP bằng VietnameseText::fold(), cùng helper mà chatbot
            // và triage engine dùng, để cả ba đồng ý một từ trông như thế nào.
            $table->string('ten_khong_dau')->nullable();
            $table->text('thanh_phan')->nullable();
            $table->text('thanh_phan_khong_dau')->nullable();
            $table->text('cach_dung')->nullable();
            // Hạn dùng do nhà sản xuất công bố, tính theo tháng kể từ ngày sản xuất —
            // thuộc tính của sản phẩm. ngay_het_han là hạn của lô hàng thực tế đang
            // giữ, do nhân viên nhập và FEFO tiêu thụ. Hai cái trả lời hai câu hỏi khác.
            $table->unsignedSmallInteger('han_dung_thang')->nullable();
            $table->decimal('gia', 10, 2);
            $table->date('ngay_het_han');
            $table->integer('so_luong_ton')->default(0);
            $table->integer('muc_canh_bao')->default(10);
            $table->string('so_lo', 100);
            $table->string('hinh_anh')->nullable();
            $table->unsignedInteger('luot_xem')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('ngay_het_han');
            $table->index('so_lo');
            $table->index('deleted_at');
            $table->index('luot_xem');
            $table->index('ten_khong_dau');
            $table->index(['ma_dm', 'ngay_het_han'], 'san_phams_ma_dm_ngay_het_han_idx');
            $table->index(['so_luong_ton', 'muc_canh_bao'], 'san_phams_so_luong_muc_canh_bao_idx');
            $table->unique(['ten', 'so_lo'], 'unique_lo_tren_san_pham');
            $table->unique('ma_san_pham', 'san_phams_ma_san_pham_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('san_phams');
    }
};
