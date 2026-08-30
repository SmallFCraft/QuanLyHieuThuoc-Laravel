<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triage_sessions', function (Blueprint $table) {
            $table->id('MaSL');
            $table->foreignId('MaKH')->constrained('users', 'MaKH')->cascadeOnDelete();
            $table->uuid('session_id')->nullable()->index();
            $table->json('collected_data')->nullable();
            $table->json('matched_symptoms')->nullable();
            $table->json('triggered_red_flags')->nullable();
            $table->enum('outcome', ['self_care', 'pharmacist_review', 'refer_doctor', 'emergency'])->nullable();
            $table->json('possible_condition_groups')->nullable();
            $table->text('advice_given')->nullable();
            $table->text('safety_net')->nullable();
            // AI output lives in its own columns so the deterministic record stays
            // reconstructible from rule_id + medical_triage_rules. advice_given and
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
                ->constrained('users', 'MaKH')->nullOnDelete();
            $table->text('pharmacist_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['MaKH', 'status'], 'triage_sessions_makh_status_idx');
            $table->index(['status', 'created_at'], 'triage_sessions_queue_idx');
            $table->index('follow_up_at');
        });

        Schema::create('medication_reminders', function (Blueprint $table) {
            $table->id('MaNN');
            $table->foreignId('MaKH')->constrained('users', 'MaKH')->cascadeOnDelete();
            $table->foreignId('MaDH')->nullable()->constrained('orders', 'MaDH')->nullOnDelete();
            $table->foreignId('MaSP')->constrained('products', 'MaSP')->cascadeOnDelete();
            $table->string('dosage_text');
            $table->unsignedTinyInteger('times_per_day')->default(1);
            $table->unsignedSmallInteger('duration_days')->default(3);
            $table->date('start_date');
            $table->timestamp('next_remind_at')->nullable();
            $table->timestamp('follow_up_at')->nullable();
            $table->enum('status', ['active', 'completed', 'cancelled'])->default('active');
            $table->timestamps();

            $table->index(['status', 'next_remind_at'], 'medication_reminders_dispatch_idx');
            $table->index(['MaKH', 'status'], 'medication_reminders_makh_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_reminders');
        Schema::dropIfExists('triage_sessions');
    }
};
