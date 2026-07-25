<?php

namespace App\Filament\App\Resources\FinancialAccounts\Pages;

use App\Filament\App\Resources\FinancialAccounts\FinancialAccountResource;
use App\Models\Company;
use App\Services\Financial\FinancialAccountService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFinancialAccount extends CreateRecord
{
    protected static string $resource = FinancialAccountResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(FinancialAccountService::class)->create($company, $data);
    }
}
