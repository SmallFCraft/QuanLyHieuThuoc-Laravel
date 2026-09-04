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
        Schema::create('products', function (Blueprint $table) {
            $table->id('MaSP');
            $table->string('product_code', 16)->nullable();
            $table->foreignId('MaDM')->constrained('categories', 'MaDM')->onDelete('restrict');
            $table->string('name');
            // Bản không dấu của các trường khách hàng tìm theo tên. Collation
            // utf8mb4_0900_ai_ci gộp dấu thanh — "á" khớp "a" — nhưng không gộp "đ",
            // nên khách gõ "dau dau" không ra gì còn "đau đầu" ra 30 sản phẩm. Fold
            // thực hiện trong PHP bằng VietnameseText::fold(), cùng helper mà chatbot
            // và triage engine dùng, để cả ba đồng ý một từ trông như thế nào.
            $table->string('name_folded')->nullable();
            $table->text('ingredients')->nullable();
            $table->text('ingredients_folded')->nullable();
            $table->text('usage')->nullable();
            // Hạn dùng do nhà sản xuất công bố, tính theo tháng kể từ ngày sản xuất —
            // thuộc tính của sản phẩm. expiration_date là hạn của lô hàng thực tế đang
            // giữ, do nhân viên nhập và FEFO tiêu thụ. Hai cái trả lời hai câu hỏi khác.
            $table->unsignedSmallInteger('shelf_life_months')->nullable();
            $table->decimal('price', 10, 2);
            $table->date('expiration_date');
            $table->integer('stock_quantity')->default(0);
            $table->integer('reorder_point')->default(10);
            $table->string('batch_number', 100);
            $table->string('image')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('expiration_date');
            $table->index('batch_number');
            $table->index('deleted_at');
            $table->index('views_count');
            $table->index('name_folded');
            $table->index(['MaDM', 'expiration_date'], 'products_madm_expiration_idx');
            $table->index(['stock_quantity', 'reorder_point'], 'products_stock_reorder_idx');
            $table->unique(['name', 'batch_number'], 'unique_batch_per_product');
            $table->unique('product_code', 'products_product_code_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
