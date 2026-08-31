<?php

namespace App\Jobs;

use App\Models\WhatsAppAutomationSend;
use App\Services\WhatsApp\Automations\WhatsAppAutomationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWhatsAppAutomationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $sendId,
    ) {}

    public function handle(WhatsAppAutomationService $automations): void
    {
        $send = WhatsAppAutomationSend::query()->find($this->sendId);

        if ($send === null) {
            return;
        }

        $automations->deliver($send);
    }
}
