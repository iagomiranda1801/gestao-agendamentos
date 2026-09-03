<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\Campaigns\WhatsAppCampaignService;
use Illuminate\Console\Command;
use Throwable;

class RequeueStuckWhatsAppCampaignsCommand extends Command
{
    protected $signature = 'whatsapp:requeue-stuck-campaigns {--minutes=5 : Minutos que o destinatário pode ficar na fila sem tentativa}';

    protected $description = 'Reenfileira destinatários de campanhas WhatsApp presos na fila';

    public function handle(WhatsAppCampaignService $campaigns): int
    {
        try {
            $queued = $campaigns->requeueStuckQueuedRecipients((int) $this->option('minutes'));

            $this->info("Destinatários reenfileirados: {$queued}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Falha ao reenfileirar campanhas WhatsApp: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
