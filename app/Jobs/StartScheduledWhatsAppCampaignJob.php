<?php

namespace App\Jobs;

use App\Enums\WhatsAppCampaignStatus;
use App\Models\WhatsAppCampaign;
use App\Services\WhatsApp\Campaigns\WhatsAppCampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class StartScheduledWhatsAppCampaignJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $campaignId,
    ) {}

    public function handle(WhatsAppCampaignService $campaigns): void
    {
        $campaign = WhatsAppCampaign::query()->find($this->campaignId);

        if (! $campaign || $campaign->status !== WhatsAppCampaignStatus::Scheduled || ! $campaign->scheduled_at) {
            return;
        }

        if ($campaign->scheduled_at->isFuture()) {
            self::dispatch($campaign->getKey())->delay($campaign->scheduled_at);

            return;
        }

        $campaigns->startSending($campaign->company, $campaign);
    }
}
