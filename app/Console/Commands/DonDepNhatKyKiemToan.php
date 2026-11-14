<?php

namespace App\Console\Commands;

use App\Models\NhatKyKiemToan;
use Illuminate\Console\Command;
use Carbon\Carbon;

class DonDepNhatKyKiemToan extends Command
{
    /**
     * Ten va chu ky cua lenh console.
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
     * Thuc thi lenh console.
     */
    public function handle()
    {
        $retentionDays = $this->option('days');
        $cutoffDate = Carbon::now()->subDays($retentionDays);

        $this->info("Cleaning up audit logs older than {$retentionDays} days (before {$cutoffDate->toDateString()})...");

        // Xoa cac nhat ky kiam toan cu
        $deleted = NhatKyKiemToan::where('created_at', '<', $cutoffDate)->delete();

        $this->info("Successfully deleted {$deleted} audit log records.");

        // Hien thi thong ke hien tai
        $remaining = NhatKyKiemToan::count();
        $this->info("Remaining audit logs: {$remaining}");
        
        return 0;
    }
}