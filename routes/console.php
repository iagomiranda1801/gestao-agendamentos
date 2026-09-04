<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('finance:generate-recurring-payables')->daily();
Schedule::command('subscriptions:expire')->daily();
Schedule::command('subscriptions:issue-due-invoices')->daily();
Schedule::command('whatsapp:process-automations')->everyFifteenMinutes();
Schedule::command('whatsapp:requeue-stuck-campaigns')->everyFiveMinutes();
Schedule::command('telescope:prune --hours=24')->hourly();
