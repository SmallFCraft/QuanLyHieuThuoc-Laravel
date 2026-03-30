<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Carbon\Carbon;

class CleanupAuditLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:cleanup {--days=365 : Number of days to retain audit logs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old audit logs to prevent database bloat';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $retentionDays = $this->option('days');
        $cutoffDate = Carbon::now()->subDays($retentionDays);
        
        $this->info("Cleaning up audit logs older than {$retentionDays} days (before {$cutoffDate->toDateString()})...");
        
        // Delete old audit logs
        $deleted = AuditLog::where('created_at', '<', $cutoffDate)->delete();
        
        $this->info("Successfully deleted {$deleted} audit log records.");
        
        // Show current stats
        $remaining = AuditLog::count();
        $this->info("Remaining audit logs: {$remaining}");
        
        return 0;
    }
}
