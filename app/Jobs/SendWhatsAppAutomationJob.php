<?php

namespace App\Jobs;

use App\Enums\WhatsAppAutomationSendStatus;
use App\Enums\WhatsAppOutboundKind;
use App\Jobs\Concerns\DefersViaWhatsAppOutboundGate;
use App\Models\WhatsAppAutomationSend;
use App\Services\WhatsApp\Automations\WhatsAppAutomationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWhatsAppAutomationJob implements ShouldQueue
{
    use DefersViaWhatsAppOutboundGate;
    use Queueable;

    public function __construct(
        public int $sendId,
    ) {}

    public function handle(WhatsAppAutomationService $automations): void
    {
        $send = WhatsAppAutomationSend::query()
            ->with(['company', 'automation'])
            ->find($this->sendId);

        if ($send === null || $send->status !== WhatsAppAutomationSendStatus::Pending) {
            return;
        }

        $company = $send->company ?? $send->automation?->company;

        if ($company === null) {
            return;
        }

        $kind = WhatsAppOutboundKind::forAutomation($send->type);

        if (! $this->deferUntilOutboundSlot($company, $kind)) {
            return;
        }

        $automations->deliver($send);
        $send->refresh();

        if ($send->status === WhatsAppAutomationSendStatus::Sent) {
            $this->rememberOutboundSuccess($company);
        } elseif ($send->status === WhatsAppAutomationSendStatus::Failed) {
            $this->rememberOutboundFailure($company);
        }
    }
}
