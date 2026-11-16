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
        Schema::create('anh_chatbots', function (Blueprint $table) {
            $table->id('ma_anh');
            $table->foreignId('ma_nd')->constrained('nguoi_dungs', 'ma_nd')->onDelete('cascade');
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

            $table->index(['ma_nd', 'session_id'], 'anh_chatbots_ma_nd_session_id_index');
        });

        // Create chat_messages table
        Schema::create('tin_nhan_chats', function (Blueprint $table) {
            $table->id('ma_tn');
            $table->foreignId('ma_nd')->constrained('nguoi_dungs', 'ma_nd')->onDelete('cascade');
            $table->uuid('session_id')->index();
            $table->enum('role', ['user', 'assistant', 'system']);
            $table->text('content');
            $table->json('products')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('show_disclaimer')->default(false);
            $table->timestamps();

            $table->index(['ma_nd', 'session_id', 'created_at'], 'tin_nhan_chats_ma_nd_session_id_created_at_index');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tin_nhan_chats');
        Schema::dropIfExists('anh_chatbots');
    }
};
