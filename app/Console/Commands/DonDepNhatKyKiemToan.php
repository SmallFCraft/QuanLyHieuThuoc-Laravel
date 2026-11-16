<?php

namespace App\Console\Commands;

use App\Models\NhatKyKiemToan;
use Illuminate\Console\Command;
use Carbon\Carbon;

class DonDepNhatKyKiemToan extends Command
{
    /**
     * Tên và chu kỳ của lệnh console.
     *
     * @var string
     */
    protected $signature = 'audit:cleanup {--days=365 : Number of days to retain audit logs}';

    /**
     * Mô tả lệnh console.
     *
     * @var string
     */
    protected $description = 'Clean up old audit logs to prevent database bloat';

    /**
     * Thực thi lệnh console.
     */
    public function handle()
    {
        $retentionDays = $this->option('days');
        $cutoffDate = Carbon::now()->subDays($retentionDays);

        $this->info("Cleaning up audit logs older than {$retentionDays} days (before {$cutoffDate->toDateString()})...");

        // Xóa các nhật ký kiểm toán cũ
        $deleted = NhatKyKiemToan::where('created_at', '<', $cutoffDate)->delete();

        $this->info("Successfully deleted {$deleted} audit log records.");

        // Hiển thị thống kê hiện tại
        $remaining = NhatKyKiemToan::count();
        $this->info("Remaining audit logs: {$remaining}");
        
        return 0;
    }
}