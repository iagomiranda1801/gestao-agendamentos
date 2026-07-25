<?php

namespace App\Console\Commands;

use App\Services\Financial\RecurringExpenseService;
use Illuminate\Console\Command;
use Throwable;

class GenerateRecurringPayablesCommand extends Command
{
    protected $signature = 'finance:generate-recurring-payables';

    protected $description = 'Gera contas a pagar a partir de templates de despesas recorrentes';

    public function handle(RecurringExpenseService $service): int
    {
        try {
            $created = $service->generateDuePayables();

            $this->info("Contas a pagar geradas: {$created}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Falha ao gerar contas recorrentes: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
