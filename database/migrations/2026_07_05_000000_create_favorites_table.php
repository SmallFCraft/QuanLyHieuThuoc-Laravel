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
        Schema::create('favorites', function (Blueprint $table) {
            $table->id('MaYT');
            $table->foreignId('MaKH')->constrained('users', 'MaKH')->cascadeOnDelete();
            $table->foreignId('MaSP')->constrained('products', 'MaSP')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['MaKH', 'MaSP'], 'favorites_user_product_unique');
            $table->index('MaSP', 'favorites_masp_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
