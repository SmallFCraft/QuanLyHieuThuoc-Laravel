<?php

namespace App\Console\Commands;

use App\Services\NhacUongThuocService;
use Illuminate\Console\Command;

class GuiNhacUongThuoc extends Command
{
    protected $signature = 'reminders:dispatch';
    protected $description = 'Gửi thông báo nhắc uống thuốc đã đến hạn';

    public function handle(NhacUongThuocService $service)
    {
        $count = $service->guiDungHan();

        $this->info("Đã gửi {$count} nhắc uống thuốc.");

        return Command::SUCCESS;
    }
}
