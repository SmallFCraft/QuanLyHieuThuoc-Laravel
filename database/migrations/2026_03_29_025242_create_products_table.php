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
            $table->text('ingredients')->nullable();
            $table->text('usage')->nullable();
            $table->decimal('price', 10, 2);
            $table->date('expiration_date');
            $table->integer('stock_quantity')->default(0);
            $table->integer('reorder_point')->default(10);
            $table->string('batch_number', 100);
            $table->string('image')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('MaDM', 'products_madm_index');
            $table->index('expiration_date');
            $table->index('batch_number');
            $table->index('deleted_at');
            $table->index('views_count');
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
