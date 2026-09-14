<?php

namespace App\Filament\App\Resources\MenuCategories\Pages;

use App\Filament\App\Resources\MenuCategories\MenuCategoryResource;
use App\Filament\App\Resources\Pages\EditRecord;
use App\Models\Company;
use App\Services\Orders\MenuCategoryService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

class EditMenuCategory extends EditRecord
{
    protected static string $resource = MenuCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(MenuCategoryService::class)->update($company, $record, $data);
    }
}
