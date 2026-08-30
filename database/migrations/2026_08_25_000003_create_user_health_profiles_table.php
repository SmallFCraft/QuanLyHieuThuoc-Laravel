<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_health_profiles', function (Blueprint $table) {
            $table->id('MaHS');
            $table->foreignId('MaKH')->unique()->constrained('users', 'MaKH')->cascadeOnDelete();
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
        Schema::dropIfExists('user_health_profiles');
    }
};
