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
        Schema::create('chi_tiet_don_hangs', function (Blueprint $table) {
            $table->id('ma_ctdh');
            $table->foreignId('ma_dh')->constrained('don_hangs', 'ma_dh')->onDelete('cascade');
            $table->foreignId('ma_sp')->constrained('san_phams', 'ma_sp')->onDelete('restrict');
            $table->foreignId('ma_lo')->nullable()->constrained('lo_san_phams', 'ma_lo')->onDelete('set null');
            $table->integer('so_luong');
            $table->decimal('don_gia', 10, 2);
            $table->string('so_lo', 100);
            $table->timestamps();

            $table->index('ma_sp', 'chi_tiet_don_hangs_ma_sp_index');
            $table->index(['ma_dh', 'ma_sp'], 'chi_tiet_don_hangs_ma_dh_ma_sp_idx');
            $table->index(['ma_lo', 'ma_sp'], 'chi_tiet_don_hangs_ma_lo_ma_sp_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chi_tiet_don_hangs');
    }
};
