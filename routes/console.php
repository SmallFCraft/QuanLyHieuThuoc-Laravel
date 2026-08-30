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
