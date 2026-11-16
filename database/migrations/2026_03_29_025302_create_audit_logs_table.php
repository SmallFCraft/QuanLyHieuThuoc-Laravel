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
        Schema::create('nhat_ky_kiem_toans', function (Blueprint $table) {
            $table->id('ma_nk');
            $table->foreignId('ma_nd')->nullable()->constrained('nguoi_dungs', 'ma_nd')->onDelete('set null');
            $table->string('action', 50);
            $table->string('model_type', 100);
            $table->unsignedBigInteger('MaDoiTuong')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index('ma_nd', 'nhat_ky_kiem_toans_ma_nd_index');
            $table->index('action');
            $table->index('created_at');
            $table->index(['model_type', 'MaDoiTuong', 'created_at'], 'audit_logs_target_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhat_ky_kiem_toans');
    }
};
