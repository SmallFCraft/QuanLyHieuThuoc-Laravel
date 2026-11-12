<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phien_sang_locs', function (Blueprint $table) {
            $table->id('ma_sl');
            $table->foreignId('ma_nd')->constrained('nguoi_dungs', 'ma_nd')->cascadeOnDelete();
            $table->uuid('session_id')->nullable()->index();
            $table->json('collected_data')->nullable();
            $table->json('matched_symptoms')->nullable();
            $table->json('triggered_red_flags')->nullable();
            $table->enum('outcome', ['self_care', 'pharmacist_review', 'refer_doctor', 'emergency'])->nullable();
            $table->json('possible_condition_groups')->nullable();
            $table->text('advice_given')->nullable();
            $table->text('safety_net')->nullable();
            // AI output lives in its own columns so the deterministic record stays
            // reconstructible from rule_id + quy_tac_phan_tang_y_tes. advice_given and
            // safety_net are never overwritten by a language model.
            //
            // Not stored here on purpose: provider/model/latency (already in
            // audit_logs.new_values, which is json) and the prompt (never logged).
            $table->text('ai_advice')->nullable();
            $table->text('ai_safety_net')->nullable();
            // Unlike ai_advice — a rewrite of pharmacist text — this holds newly
            // generated sections (why, what to watch, when to see a doctor, questions
            // to ask, picked product ids). Rendered as clearly labelled AI content.
            $table->json('ai_briefing')->nullable();
            // null = never attempted. ok | rejected | failed. 'rejected' records that
            // output existed but failed the PHP safety checks, which null cannot say.
            $table->string('ai_briefing_status', 20)->nullable();
            // string, not enum: widening an enum needs per-driver branches, and the
            // suite runs on sqlite.
            // skipped | ok | failed | rejected — 'rejected' records that a rewrite was
            // produced but failed the PHP safety check, which a null cannot express.
            $table->string('ai_status', 20)->nullable();
            // Validated facts + clarify questions. Deliberately NOT merged into
            // collected_data: the rule engine reads that column, and mixing AI output
            // into the text it matches against would blur the safety boundary.
            $table->json('ai_meta')->nullable();
            // Anti-repeat counter: clarification is a bounded conversation, so the
            // session has to know which round it is on.
            $table->unsignedTinyInteger('ai_clarify_rounds')->default(0);
            $table->json('suggested_products')->nullable();
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->enum('entry_point', ['chat', 'page'])->default('page');
            $table->timestamp('follow_up_at')->nullable();
            // Follow-up links live in email and can be opened more than once,
            // so the answer needs its own marker to stay idempotent.
            $table->timestamp('follow_up_responded_at')->nullable();
            $table->boolean('follow_up_improved')->nullable();
            $table->enum('status', ['in_progress', 'completed', 'pending_pharmacist', 'reviewed'])
                ->default('in_progress');
            $table->foreignId('pharmacist_id')->nullable()
                ->constrained('nguoi_dungs', 'ma_nd')->nullOnDelete();
            $table->text('pharmacist_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['ma_nd', 'status'], 'phien_sang_locs_ma_nd_status_idx');
            $table->index(['status', 'created_at'], 'triage_sessions_queue_idx');
            $table->index('follow_up_at');
        });

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
        Schema::dropIfExists('phien_sang_locs');
    }
};
