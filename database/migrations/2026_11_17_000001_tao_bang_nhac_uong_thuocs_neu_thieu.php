<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hosting DB thiếu bảng nhac_uong_thuocs dù mọi migration khác đã chạy
     * (cron reminders:dispatch fail SQLSTATE 42S02 mỗi 15 phút). Bản dựng lại
     * khớp nguyên bản gốc trong 2026_08_25_000004; idempotent, nên không đụng
     * môi trường nào đã có bảng.
     */
    public function up(): void
    {
        if (Schema::hasTable('nhac_uong_thuocs')) {
            return;
        }

        Schema::create('nhac_uong_thuocs', function (Blueprint $table) {
            $table->id('ma_nn');
            $table->foreignId('ma_nd')->constrained('nguoi_dungs', 'ma_nd')->cascadeOnDelete();
            $table->foreignId('ma_dh')->nullable()->constrained('don_hangs', 'ma_dh')->nullOnDelete();
            $table->foreignId('ma_sp')->constrained('san_phams', 'ma_sp')->cascadeOnDelete();
            $table->string('dosage_text');
            $table->unsignedTinyInteger('times_per_day')->default(1);
            $table->unsignedSmallInteger('duration_days')->default(3);
            $table->date('start_date');
            $table->timestamp('next_remind_at')->nullable();
            $table->timestamp('follow_up_at')->nullable();
            $table->enum('status', ['active', 'completed', 'cancelled'])->default('active');
            $table->timestamps();

            $table->index(['status', 'next_remind_at'], 'medication_reminders_dispatch_idx');
            $table->index(['ma_nd', 'status'], 'nhac_uong_thuocs_ma_nd_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nhac_uong_thuocs');
    }
};
