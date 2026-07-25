<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('finance:generate-recurring-payables')->daily();
