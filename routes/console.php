<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule cleanup tasks
Schedule::command('audit:cleanup')->daily()->at('02:00');
Schedule::command('images:cleanup')->weekly()->sundays()->at('03:00');
Schedule::command('chatbot:cleanup-images')->hourly();
Schedule::command('chat:cleanup')->daily()->at('03:00');
