<?php

namespace App\Console\Commands;

use App\Services\Triage\TriageFollowUpService;
use Illuminate\Console\Command;

class DispatchTriageFollowUps extends Command
{
    protected $signature = 'triage:follow-up';
    protected $description = 'Hỏi lại khách sau tư vấn tự chăm sóc xem triệu chứng đỡ chưa';

    public function handle(TriageFollowUpService $service)
    {
        $count = $service->dispatchDue();

        $this->info("Đã gửi {$count} lượt theo dõi sau tư vấn.");

        return Command::SUCCESS;
    }
}
