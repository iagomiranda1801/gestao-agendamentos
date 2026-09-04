<?php

namespace App\Console\Commands;

use App\Services\Company\CompanySubscriptionService;
use Illuminate\Console\Command;

class IssueDuePlatformInvoicesCommand extends Command
{
    protected $signature = 'subscriptions:issue-due-invoices';

    protected $description = 'Emite faturas para empresas com trial ou período pago perto do vencimento';

    public function handle(CompanySubscriptionService $subscriptions): int
    {
        $issued = $subscriptions->issueDueInvoices();

        $this->info("Faturas emitidas: {$issued}");

        return self::SUCCESS;
    }
}
