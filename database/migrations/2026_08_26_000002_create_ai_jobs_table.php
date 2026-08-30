<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A status table of its own, not Laravel's `jobs`: the worker deletes a row
        // from `jobs` the moment it picks it up, so there is nothing left to poll.
        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->id('MaAJ');
            // Public identifier handed to the browser, so the auto-increment key is
            // never exposed. Ownership is still checked on every poll.
            $table->uuid('uuid')->unique();
            $table->foreignId('MaKH')->constrained('users', 'MaKH')->cascadeOnDelete();
            $table->string('kind', 32);
            // string, not enum: widening an enum needs per-driver branches (MySQL
            // MODIFY, Postgres CHECK, SQLite table rebuild), and the suite runs on
            // sqlite.
            $table->string('status', 20)->default('queued');
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['MaKH', 'status'], 'ai_jobs_makh_status_idx');
            $table->index(['status', 'created_at'], 'ai_jobs_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_jobs');
    }
};
