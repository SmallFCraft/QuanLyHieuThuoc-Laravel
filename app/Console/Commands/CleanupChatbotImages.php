<?php

namespace App\Console\Commands;

use App\Models\ChatbotImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class CleanupChatbotImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chatbot:cleanup-images {--hours=24 : Age in hours for images to be deleted}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete chatbot images older than specified hours (default: 24)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoffTime = now()->subHours($hours);
        $disk = Storage::disk('local');
        
        $this->info("Cleaning up chatbot images older than {$hours} hours...");

        if (! ChatbotImage::where('created_at', '<', $cutoffTime)->exists()) {
            $this->info('No images to clean up.');
            return Command::SUCCESS;
        }
        
        $deletedCount = 0;
        $errorCount = 0;
        
        ChatbotImage::where('created_at', '<', $cutoffTime)
            ->chunkById(100, function ($oldImages) use ($disk, &$deletedCount, &$errorCount) {
                foreach ($oldImages as $image) {
                    try {
                        $path = $image->file_path ?? $image->image_path;

                        if ($path && $disk->exists($path)) {
                            $disk->delete($path);
                        }

                        $image->forceDelete();
                        $deletedCount++;
                    } catch (\Exception $e) {
                        $errorCount++;

                        Log::error('Failed to delete chatbot image', [
                            'image_id' => $image->id,
                            'file_path' => $image->file_path ?? $image->image_path,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            });
        
        $this->info("Cleanup complete:");
        $this->info("- Deleted: {$deletedCount} images");
        
        if ($errorCount > 0) {
            $this->warn("- Errors: {$errorCount} images");
        }
        
        Log::info('Chatbot images cleanup completed', [
            'deleted_count' => $deletedCount,
            'error_count' => $errorCount,
            'hours' => $hours
        ]);
        
        return Command::SUCCESS;
    }
}
