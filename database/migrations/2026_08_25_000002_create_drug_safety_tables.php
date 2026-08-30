<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drug_interactions', function (Blueprint $table) {
            $table->id('MaTT');
            $table->string('ingredient_a', 120);
            $table->string('ingredient_b', 120);
            $table->enum('severity', ['major', 'moderate', 'minor'])->default('moderate');
            $table->text('description_vi');
            $table->text('recommendation_vi');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['ingredient_a', 'ingredient_b'], 'drug_interactions_pair_unique');
            $table->index('ingredient_a');
            $table->index('ingredient_b');
        });

        Schema::create('drug_contraindications', function (Blueprint $table) {
            $table->id('MaCCD');
            $table->string('ingredient', 120);
            $table->enum('condition_type', [
                'pregnancy', 'breastfeeding', 'age_under', 'age_over', 'chronic_disease', 'allergy',
            ]);
            $table->string('condition_value', 120)->nullable();
            $table->enum('severity', ['absolute', 'relative'])->default('relative');
            $table->text('warning_vi');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['ingredient', 'condition_type'], 'drug_contraindications_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drug_contraindications');
        Schema::dropIfExists('drug_interactions');
    }
};
