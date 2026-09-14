<?php

namespace App\Filament\App\Concerns;

use App\Models\Company;
use App\Services\Orders\MenuCategoryService;
use Filament\Facades\Filament;

trait SeedsMenuCategoryDefaults
{
    protected function seedMenuCategoryDefaults(): void
    {
        $company = Filament::getTenant();

        if ($company instanceof Company) {
            app(MenuCategoryService::class)->ensureDefaults($company);
        }
    }
}
