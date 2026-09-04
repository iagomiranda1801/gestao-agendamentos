<?php

namespace App\Filament\Admin\Resources\ModulePrices\Pages;

use App\Filament\Admin\Resources\ModulePrices\ModulePriceResource;
use Filament\Resources\Pages\EditRecord;

class EditModulePrice extends EditRecord
{
    protected static string $resource = ModulePriceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
