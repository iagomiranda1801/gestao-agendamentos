<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('finance:generate-recurring-payables')->daily();
Schedule::command('subscriptions:expire')->daily();
Schedule::command('subscriptions:issue-due-invoices')->daily();
Schedule::command('whatsapp:process-automations')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('whatsapp:requeue-stuck-campaigns')->everyFiveMinutes();
Schedule::command('whatsapp:cleanup-bot-conversations')->hourly();
Schedule::command('telescope:prune --hours=24')->hourly();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
