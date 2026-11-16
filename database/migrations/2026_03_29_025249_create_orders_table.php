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
        Schema::create('don_hangs', function (Blueprint $table) {
            $table->id('ma_dh');
            $table->foreignId('ma_nd')->constrained('nguoi_dungs', 'ma_nd')->onDelete('restrict');
            $table->decimal('tong_tien', 10, 2);
            $table->enum('trang_thai', ['Pending', 'Delivering', 'Completed', 'Cancelled'])->default('Pending');
            $table->text('dia_chi_giao_hang')->nullable();
            $table->string('sdt_giao_hang', 20)->nullable();
            $table->string('thoi_gian_giao', 50)->nullable();
            $table->text('ghi_chu')->nullable();
            $table->timestamps();

            $table->index('ma_nd', 'don_hangs_ma_nd_index');
            $table->index(['trang_thai', 'created_at'], 'don_hangs_trang_thai_created_idx');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('don_hangs');
    }
};
