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
        Schema::create('yeu_thiches', function (Blueprint $table) {
            $table->id('ma_yt');
            $table->foreignId('ma_nd')->constrained('nguoi_dungs', 'ma_nd')->cascadeOnDelete();
            $table->foreignId('ma_sp')->constrained('san_phams', 'ma_sp')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ma_nd', 'ma_sp'], 'yeu_thiches_nguoi_dung_san_pham_unique');
            $table->index('ma_sp', 'yeu_thiches_ma_sp_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('yeu_thiches');
    }
};
