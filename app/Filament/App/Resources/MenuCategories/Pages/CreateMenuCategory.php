<?php

namespace App\Filament\App\Resources\MenuCategories\Pages;

use App\Filament\App\Resources\MenuCategories\MenuCategoryResource;
use App\Filament\App\Resources\Pages\CreateRecord;
use App\Models\Company;
use App\Services\Orders\MenuCategoryService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

class CreateMenuCategory extends CreateRecord
{
    protected static string $resource = MenuCategoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(MenuCategoryService::class)->create($company, $data);
    }
}
