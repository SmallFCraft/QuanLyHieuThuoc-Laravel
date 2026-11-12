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
        Schema::create('nguoi_dungs', function (Blueprint $table) {
            $table->id('ma_nd');
            $table->string('ho_ten');
            $table->string('email')->unique();
            $table->timestamp('thoi_diem_xac_thuc_email')->nullable();
            $table->date('ngay_sinh')->nullable();
            $table->string('gioi_tinh', 20)->nullable();
            $table->text('dia_chi')->nullable();
            $table->string('so_dien_thoai', 20)->nullable();
            // Đường dẫn tương đối trên disk 'public' (vd "avatars/<uuid>.jpg"), không
            // phải URL đầy đủ: đổi APP_URL hoặc chuyển disk thì không phải sửa dữ liệu.
            $table->string('anh_dai_dien')->nullable();
            $table->string('password');
            $table->enum('vai_tro', ['Administrator', 'Pharmacist', 'User'])->default('User');
            $table->enum('trang_thai', ['active', 'inactive'])->default('active');
            $table->rememberToken();
            $table->timestamps();

            $table->index('vai_tro');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->references('ma_nd')->on('nguoi_dungs')->onDelete('cascade');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('nguoi_dungs');
    }
};
