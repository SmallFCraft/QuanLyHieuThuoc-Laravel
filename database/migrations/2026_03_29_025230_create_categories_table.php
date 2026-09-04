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
        Schema::create('categories', function (Blueprint $table) {
            $table->id('MaDM');
            $table->string('name')->unique();
            // Bản không dấu, không dấu phân cách của name — đây mới là danh tính thật
            // của một danh mục. Long Châu công bố cùng một breadcrumb cấp 2 dưới nhiều
            // cách viết ("Cơ - xương - khớp" và "Cơ xương khớp"), collation
            // utf8mb4_0900_ai_ci gộp dấu thanh nhưng không gộp "đ" lẫn dấu gạch, nên
            // unique trên name một mình không bắt được. Fold bằng
            // VietnameseText::fold(), cùng helper mà cột tìm kiếm của products dùng.
            //
            // Lần gộp trùng một lần duy nhất (2026-09-01, 51 → 50 danh mục, 114 sản
            // phẩm dồn về MaDM 6) đã chạy trên DB dev; snapshot trước khi gộp nằm ở
            // storage/backups/categories-premerge-20260901-124244.json. Bảng dựng mới
            // không cần bước đó — unique index bên dưới chặn tái diễn.
            $table->string('name_folded')->nullable();
            $table->string('code', 10)->nullable();
            $table->string('icon', 50)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique('code', 'categories_code_unique');
            $table->unique('name_folded', 'categories_name_folded_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
