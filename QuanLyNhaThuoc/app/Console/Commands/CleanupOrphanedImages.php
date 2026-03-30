<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class CleanupOrphanedImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'images:cleanup {--days=30 : Delete images from products soft-deleted more than X days ago}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up orphaned product images from soft-deleted products';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = $this->option('days');
        $cutoffDate = Carbon::now()->subDays($days);
        
        $this->info("Cleaning up images from products soft-deleted before {$cutoffDate->toDateString()}...");
        
        $deletedProducts = Product::onlyTrashed()
            ->where('deleted_at', '<', $cutoffDate)
            ->whereNotNull('image')
            ->get();
        
        $deletedCount = 0;
        
        foreach ($deletedProducts as $product) {
            $imagePath = 'products/' . $product->image;
            
            if (Storage::disk('public')->exists($imagePath)) {
                Storage::disk('public')->delete($imagePath);
                $this->info("Deleted image: {$product->image}");
                $deletedCount++;
            }
        }
        
        $this->info("Successfully deleted {$deletedCount} orphaned images.");
        
        return 0;
    }
}
