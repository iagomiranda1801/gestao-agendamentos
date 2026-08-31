<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('finance:generate-recurring-payables')->daily();
Schedule::command('whatsapp:process-automations')->everyFifteenMinutes();
Schedule::command('telescope:prune --hours=24')->hourly();
