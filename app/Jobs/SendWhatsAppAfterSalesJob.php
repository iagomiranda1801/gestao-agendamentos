<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Services\WhatsApp\Automations\WhatsAppAutomationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWhatsAppAfterSalesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $attendanceId,
    ) {}

    public function handle(WhatsAppAutomationService $automations): void
    {
        $attendance = Attendance::query()->find($this->attendanceId);

        if ($attendance === null) {
            return;
        }

        $automations->sendAfterSales($attendance);
    }
}
