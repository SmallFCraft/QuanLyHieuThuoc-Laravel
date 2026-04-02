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
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->uuid('session_id')->index();
            $table->enum('role', ['user', 'assistant', 'system']);
            $table->text('content');
            $table->json('products')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('show_disclaimer')->default(false);
            $table->timestamps();
            
            $table->index(['user_id', 'session_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
