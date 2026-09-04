<?php

namespace App\Filament\App\Resources\ExpenseCategories\Pages;

use App\Filament\App\Resources\ExpenseCategories\ExpenseCategoryResource;
use App\Models\Company;
use App\Services\Financial\ExpenseCategoryService;
use Filament\Facades\Filament;
use App\Filament\App\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditExpenseCategory extends EditRecord
{
    protected static string $resource = ExpenseCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(ExpenseCategoryService::class)->update($company, $record, $data);
    }
}
