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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id('MaCTDH');
            $table->foreignId('MaDH')->constrained('orders', 'MaDH')->onDelete('cascade');
            $table->foreignId('MaSP')->constrained('products', 'MaSP')->onDelete('restrict');
            $table->foreignId('MaLo')->nullable()->constrained('product_batches', 'MaLo')->onDelete('set null');
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->string('batch_number', 100);
            $table->timestamps();

            $table->index('MaSP', 'order_items_masp_index');
            $table->index(['MaDH', 'MaSP'], 'order_items_madh_masp_idx');
            $table->index(['MaLo', 'MaSP'], 'order_items_malo_masp_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
