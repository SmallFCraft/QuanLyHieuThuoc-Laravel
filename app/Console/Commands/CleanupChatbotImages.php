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
    protected $signature = 'chatbot:cleanup-images {--hours= : Age in hours for images to be deleted (default: chatbot.images.cleanup_after)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete chatbot images older than specified hours (default: chatbot.images.cleanup_after)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?? config('chatbot.images.cleanup_after', 24));

        if ($hours < 1) {
            $this->error('--hours must be at least 1.');

            return Command::FAILURE;
        }

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
                            'MaAnh' => $image->MaAnh,
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
