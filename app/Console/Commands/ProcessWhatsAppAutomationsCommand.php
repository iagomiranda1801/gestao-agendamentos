<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\Automations\WhatsAppAutomationService;
use Illuminate\Console\Command;
use Throwable;

class ProcessWhatsAppAutomationsCommand extends Command
{
    protected $signature = 'whatsapp:process-automations';

    protected $description = 'Envia lembretes, pós-venda e reconquista de WhatsApp que já estão na janela';

    public function handle(WhatsAppAutomationService $automations): int
    {
        try {
            $queued = $automations->processDue();

            $this->info("Automações enfileiradas: {$queued}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Falha ao processar automações de WhatsApp: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
