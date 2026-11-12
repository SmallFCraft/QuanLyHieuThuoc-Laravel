<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ho_so_suc_khoes', function (Blueprint $table) {
            $table->id('ma_hs');
            $table->foreignId('ma_nd')->unique()->constrained('nguoi_dungs', 'ma_nd')->cascadeOnDelete();
            // Encrypted at the model layer (encrypted:array cast) — health data is sensitive.
            $table->text('allergies')->nullable();
            $table->text('chronic_conditions')->nullable();
            $table->text('current_medications')->nullable();
            $table->boolean('is_pregnant')->default(false);
            $table->boolean('is_breastfeeding')->default(false);
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->text('notes')->nullable();
            // Personalisation is skipped entirely while this is null.
            $table->timestamp('consented_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ho_so_suc_khoes');
    }
};
