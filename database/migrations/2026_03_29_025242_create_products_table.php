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
            // Ban khong dau cua cac truong khach hang tim theo ten. Collation
            // utf8mb4_0900_ai_ci gop dau thanh — "a" khop "a" — nhung khong gop "d",
            // nen khach go "dau dau" khong ra gi con "dau dau" ra 30 san pham. Fold
            // thuc hien trong PHP bang VietnameseText::fold(), cung helper ma chatbot
            // va triage engine dung, de ca ba dong y mot tu trong nhu the nao.
            $table->string('ten_khong_dau')->nullable();
            $table->text('thanh_phan')->nullable();
            $table->text('thanh_phan_khong_dau')->nullable();
            $table->text('cach_dung')->nullable();
            // Han dung do nha san xuat cong bo, tinh theo thang ke tu ngay san xuat —
            // thuoc tinh cua san pham. ngay_het_han la han cua lo hang thuc te dang
            // giu, do nhan vien nhap va FEFO tieu thu. Hai cai tra loi hai cau hoi khac.
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
