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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict');
            $table->decimal('total_amount', 10, 2);
            $table->enum('status', ['Pending', 'Delivering', 'Completed', 'Cancelled'])->default('Pending');
            $table->text('shipping_address')->nullable();
            $table->string('shipping_phone', 20)->nullable();
            $table->string('delivery_time', 50)->nullable();
            $table->text('order_notes')->nullable();
            $table->timestamps();
            
            $table->index('user_id');
            $table->index(['status', 'created_at'], 'orders_status_created_idx');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
