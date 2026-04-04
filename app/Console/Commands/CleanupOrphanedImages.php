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
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoffDate = Carbon::now()->subDays($days);
        $disk = Storage::disk('public');
        
        $this->info("Cleaning up images from products soft-deleted before {$cutoffDate->toDateString()}...");

        $deletedCount = 0;

        Product::onlyTrashed()
            ->where('deleted_at', '<', $cutoffDate)
            ->whereNotNull('image')
            ->chunkById(100, function ($deletedProducts) use ($disk, &$deletedCount) {
                foreach ($deletedProducts as $product) {
                    $imagePath = 'products/' . $product->image;

                    if ($disk->exists($imagePath)) {
                        $disk->delete($imagePath);
                        $this->info("Deleted image: {$product->image}");
                        $deletedCount++;
                    }
                }
            });

        $this->info("Successfully deleted {$deletedCount} orphaned images.");

        return Command::SUCCESS;
    }
}
