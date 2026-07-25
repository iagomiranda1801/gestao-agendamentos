<?php

namespace App\Filament\App\Resources\FinancialTransactions\Pages;

use App\Filament\App\Resources\FinancialTransactions\FinancialTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListFinancialTransactions extends ListRecords
{
    protected static string $resource = FinancialTransactionResource::class;
}
