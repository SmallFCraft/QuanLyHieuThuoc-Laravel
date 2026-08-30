<?php

namespace App\Console\Commands;

use App\Services\MedicationReminderService;
use Illuminate\Console\Command;

class DispatchMedicationReminders extends Command
{
    protected $signature = 'reminders:dispatch';
    protected $description = 'Gửi thông báo nhắc uống thuốc đã đến hạn';

    public function handle(MedicationReminderService $service)
    {
        $count = $service->dispatchDue();

        $this->info("Đã gửi {$count} nhắc uống thuốc.");

        return Command::SUCCESS;
    }
}
