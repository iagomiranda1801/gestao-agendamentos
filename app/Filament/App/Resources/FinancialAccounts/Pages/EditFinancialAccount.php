<?php

namespace App\Filament\App\Resources\FinancialAccounts\Pages;

use App\Filament\App\Resources\FinancialAccounts\FinancialAccountResource;
use App\Models\Company;
use App\Services\Financial\FinancialAccountService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditFinancialAccount extends EditRecord
{
    protected static string $resource = FinancialAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(FinancialAccountService::class)->update($company, $record, $data);
    }
}
