<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\ProductBatch;

class ProductObserver
{
    /**
     * Handle the Product "created" event.
     */
    public function created(Product $product): void
    {
        // Auto-create a batch when product is created
        if ($product->batch_number && $product->expiration_date) {
            ProductBatch::create([
                'product_id' => $product->id,
                'batch_number' => $product->batch_number,
                'expiration_date' => $product->expiration_date,
                'stock_quantity' => $product->stock_quantity ?? 0,
                'reorder_point' => $product->reorder_point ?? 10,
            ]);
        }
    }

    /**
     * Handle the Product "updated" event.
     */
    public function updated(Product $product): void
    {
        //
    }

    /**
     * Handle the Product "deleted" event.
     */
    public function deleted(Product $product): void
    {
        //
    }

    /**
     * Handle the Product "restored" event.
     */
    public function restored(Product $product): void
    {
        //
    }

    /**
     * Handle the Product "force deleted" event.
     */
    public function forceDeleted(Product $product): void
    {
        //
    }
}
