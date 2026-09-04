<?php

namespace App\Console\Commands;

use App\Services\Company\CompanySubscriptionService;
use Illuminate\Console\Command;

class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Marca faturas em atraso e empresas com trial ou período pago já encerrado';

    public function handle(CompanySubscriptionService $subscriptions): int
    {
        $overdue = $subscriptions->markOverdueInvoices();
        $expired = $subscriptions->expireOverdue();

        $this->info("Faturas marcadas como vencidas: {$overdue}");
        $this->info("Assinaturas expiradas: {$expired}");

        return self::SUCCESS;
    }
}
