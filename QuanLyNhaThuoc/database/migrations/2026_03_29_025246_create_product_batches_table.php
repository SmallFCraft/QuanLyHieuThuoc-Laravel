<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('batch_number')->unique();
            $table->date('expiration_date');
            $table->integer('stock_quantity')->default(0);
            $table->integer('reorder_point')->default(10);
            $table->timestamps();

            $table->index(['product_id', 'expiration_date']);
            $table->index(['expiration_date', 'stock_quantity']);
        });

        DB::statement('
            INSERT INTO product_batches (product_id, batch_number, expiration_date, stock_quantity, reorder_point, created_at, updated_at)
            SELECT id, batch_number, expiration_date, stock_quantity, reorder_point, created_at, updated_at
            FROM products
            WHERE deleted_at IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_batches');
    }
};
