<?php

namespace App\Console\Commands;

use App\Models\TinNhanChat;
use Illuminate\Console\Command;

class DonDepTinNhanChat extends Command
{
    protected $signature = 'chat:cleanup';
    protected $description = 'Xóa tin nhắn chat cũ hơn 30 ngày';

    public function handle()
    {
        $days = 30;
        $date = now()->subDays($days);
        
        $count = TinNhanChat::where('created_at', '<', $date)->delete();
        
        $this->info("Đã xóa {$count} tin nhắn chat cũ hơn {$days} ngày.");
        
        return 0;
    }
}
