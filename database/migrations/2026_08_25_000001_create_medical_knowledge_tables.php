<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trieu_chung_y_tes', function (Blueprint $table) {
            $table->id('ma_tc');
            $table->string('code', 50)->unique();
            $table->string('name_vi');
            $table->json('aliases');
            // Words that make an alias mean something ordinary: "nước sốt" is a sauce,
            // "con ngựa" is a horse. Stored next to the aliases they disambiguate so a
            // pharmacist adding a short ambiguous alias can guard it without a code change.
            $table->json('guard_words')->nullable();
            $table->enum('body_system', [
                'respiratory', 'digestive', 'neuro', 'derma', 'musculoskeletal', 'general',
            ])->default('general');
            $table->string('catalog_query')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'body_system'], 'medical_symptoms_active_system_idx');
        });

        Schema::create('co_do_y_tes', function (Blueprint $table) {
            $table->id('ma_cb');
            $table->string('code', 50)->unique();
            $table->string('name_vi');
            $table->json('keywords');
            $table->enum('severity', ['emergency', 'urgent', 'caution'])->default('urgent');
            // Severity answers "how bad", not "should the chatbot stop everything".
            // Rows like "đang mang thai" or "bé 1 tuổi" are patient context that the
            // wizard must weigh, but in chat they would turn an ordinary product
            // question into emergency guidance. This column separates the two uses.
            $table->boolean('chat_short_circuit')->default(true);
            $table->text('guidance_vi');
            $table->foreignId('applies_to_symptom')->nullable()
                ->constrained('trieu_chung_y_tes', 'ma_tc')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'severity'], 'medical_red_flags_active_severity_idx');
        });

        Schema::create('quy_tac_phan_tang_y_tes', function (Blueprint $table) {
            $table->id('ma_qt');
            $table->foreignId('ma_tc')->constrained('trieu_chung_y_tes', 'ma_tc')->cascadeOnDelete();
            $table->json('conditions')->nullable();
            $table->enum('outcome', ['self_care', 'pharmacist_review', 'refer_doctor', 'emergency']);
            $table->text('advice_vi')->nullable();
            $table->text('safety_net_vi')->nullable();
            $table->string('condition_group_vi')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->json('suggest_categories')->nullable();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->foreignId('reviewed_by')->nullable()
                ->constrained('nguoi_dungs', 'ma_nd')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['ma_tc', 'is_active', 'priority'], 'quy_tac_phan_tang_y_tes_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quy_tac_phan_tang_y_tes');
        Schema::dropIfExists('co_do_y_tes');
        Schema::dropIfExists('trieu_chung_y_tes');
    }
};
