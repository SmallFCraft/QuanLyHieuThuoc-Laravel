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

// Medical triage follow-up and medication reminders.
// withoutOverlapping: a slow batch must not let the next tick re-read the same due
// rows and mail a patient twice.
Schedule::command('reminders:dispatch')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('triage:follow-up')->daily()->at('09:00')->withoutOverlapping();

// AI job bookkeeping: closes jobs whose worker died and drops the stored payload,
// which contains what the customer typed.
Schedule::command('ai-jobs:cleanup')->hourly()->withoutOverlapping();

// Catalog refresh from Nhà Thuốc Long Châu. Weekly, not hourly: drug listings move
// slowly, and a tighter loop only raises the chance of being rate-limited.
Schedule::command('crawl:longchau')->weekly()->sundays()->at('04:00')->withoutOverlapping();

// Sau crawl:longchau (04:00) một tiếng: index phiên âm bám theo catalog mới ngay cả
// khi crawler kết thúc bất thường và không kịp tự gọi.
Schedule::command('voice:build-phonetics')->weekly()->sundays()->at('05:00')->withoutOverlapping();
