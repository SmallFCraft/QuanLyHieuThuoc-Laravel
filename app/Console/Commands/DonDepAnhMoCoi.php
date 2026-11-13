<?php

namespace App\Console\Commands;

use App\Models\SanPham;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class DonDepAnhMoCoi extends Command
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

        SanPham::onlyTrashed()
            ->where('deleted_at', '<', $cutoffDate)
            ->whereNotNull('hinh_anh')
            ->chunkById(100, function ($deletedProducts) use ($disk, &$deletedCount) {
                foreach ($deletedProducts as $product) {
                    $imagePath = str_contains($product->hinh_anh, '/')
                        ? $product->hinh_anh
                        : 'products/' . $product->hinh_anh;

                    if ($disk->exists($imagePath)) {
                        $disk->delete($imagePath);
                        $this->info("Deleted image: {$product->hinh_anh}");
                        $deletedCount++;
                    }
                }
            });

        $this->info("Successfully deleted {$deletedCount} orphaned images.");

        return Command::SUCCESS;
    }
}
