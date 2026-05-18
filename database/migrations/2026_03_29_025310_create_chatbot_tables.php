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
        // Create chatbot_images table
        Schema::create('chatbot_images', function (Blueprint $table) {
            $table->id('MaAnh');
            $table->foreignId('MaKH')->constrained('users', 'MaKH')->onDelete('cascade');
            $table->uuid('session_id')->index();
            $table->string('filename')->nullable();
            $table->string('original_name')->nullable();
            $table->string('image_path');
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->enum('image_type', ['prescription', 'receipt'])->default('prescription');
            $table->text('analysis_result')->nullable();
            $table->longText('ocr_text')->nullable();
            $table->json('recognized_products')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['MaKH', 'session_id'], 'chatbot_images_makh_session_id_index');
        });

        // Create chat_messages table
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id('MaTN');
            $table->foreignId('MaKH')->constrained('users', 'MaKH')->onDelete('cascade');
            $table->uuid('session_id')->index();
            $table->enum('role', ['user', 'assistant', 'system']);
            $table->text('content');
            $table->json('products')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('show_disclaimer')->default(false);
            $table->timestamps();
            
            $table->index(['MaKH', 'session_id', 'created_at'], 'chat_messages_makh_session_id_created_at_index');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chatbot_images');
    }
};
