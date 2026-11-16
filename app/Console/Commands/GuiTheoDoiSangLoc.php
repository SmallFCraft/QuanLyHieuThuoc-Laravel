<?php

namespace App\Console\Commands;

use App\Services\Triage\TheoDoiSangLocService;
use Illuminate\Console\Command;

class GuiTheoDoiSangLoc extends Command
{
    protected $signature = 'triage:follow-up';
    protected $description = 'Hỏi lại khách sau tư vấn tự chăm sóc xem triệu chứng đỡ chưa';

    public function handle(TheoDoiSangLocService $service)
    {
        $count = $service->guiDungHan();

        $this->info("Đã gửi {$count} lượt theo dõi sau tư vấn.");

        return Command::SUCCESS;
    }
}
