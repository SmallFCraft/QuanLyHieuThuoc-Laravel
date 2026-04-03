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
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->onDelete('restrict');
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
            
            $table->index('category_id');
            $table->index('expiration_date');
            $table->index('batch_number');
            $table->index('deleted_at');
            $table->index('views_count');
            $table->index(['category_id', 'expiration_date'], 'products_category_expiration_idx');
            $table->index(['stock_quantity', 'reorder_point'], 'products_stock_reorder_idx');
            $table->unique(['name', 'batch_number'], 'unique_batch_per_product');
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
