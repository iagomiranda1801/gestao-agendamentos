<?php

namespace App\Jobs;

use App\Mail\AppointmentChangeMail;
use App\Models\WhatsAppCampaignRecipient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWhatsAppCampaignEmailJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 55;

    public int $tries = 3;

    public function __construct(public int $recipientId) {}

    public function handle(): void
    {
        $recipient = WhatsAppCampaignRecipient::query()
            ->with('campaign.company')
            ->find($this->recipientId);

        if ($recipient === null || blank($recipient->email_snapshot)) {
            return;
        }

        $company = $recipient->campaign?->company;

        if ($company === null) {
            return;
        }

        try {
            Mail::to($recipient->email_snapshot)->send(new AppointmentChangeMail(
                "Campanha {$recipient->campaign->name} - {$company->name}",
                $recipient->message_snapshot,
            ));
        } catch (Throwable $exception) {
            Log::warning('WhatsApp campaign email notification failed.', [
                'campaign_id' => $recipient->campaign->getKey(),
                'recipient_id' => $recipient->getKey(),
                'email' => $recipient->email_snapshot,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
