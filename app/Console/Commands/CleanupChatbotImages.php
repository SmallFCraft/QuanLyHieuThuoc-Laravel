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
        
        $this->info("Cleaning up chatbot images older than {$hours} hours...");
        
        // Find old images
        $oldImages = ChatbotImage::where('created_at', '<', $cutoffTime)->get();
        
        if ($oldImages->isEmpty()) {
            $this->info('No images to clean up.');
            return Command::SUCCESS;
        }
        
        $deletedCount = 0;
        $errorCount = 0;
        
        foreach ($oldImages as $image) {
            try {
                // Delete physical file
                if (Storage::disk('local')->exists($image->file_path)) {
                    Storage::disk('local')->delete($image->file_path);
                }
                
                // Delete database record
                $image->forceDelete();
                
                $deletedCount++;
                
            } catch (\Exception $e) {
                $errorCount++;
                Log::error('Failed to delete chatbot image', [
                    'image_id' => $image->id,
                    'file_path' => $image->file_path,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
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
