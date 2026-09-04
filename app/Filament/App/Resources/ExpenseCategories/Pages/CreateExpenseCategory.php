<?php

namespace App\Filament\App\Resources\ExpenseCategories\Pages;

use App\Filament\App\Resources\ExpenseCategories\ExpenseCategoryResource;
use App\Models\Company;
use App\Services\Financial\ExpenseCategoryService;
use Filament\Facades\Filament;
use App\Filament\App\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateExpenseCategory extends CreateRecord
{
    protected static string $resource = ExpenseCategoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(ExpenseCategoryService::class)->create($company, $data);
    }
}
