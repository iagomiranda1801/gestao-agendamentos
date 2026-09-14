<?php

namespace App\Filament\App\Resources\MenuCategories\Pages;

use App\Filament\App\Concerns\SeedsMenuCategoryDefaults;
use App\Filament\App\Resources\MenuCategories\MenuCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMenuCategories extends ListRecords
{
    use SeedsMenuCategoryDefaults;

    protected static string $resource = MenuCategoryResource::class;

    public function mount(): void
    {
        parent::mount();

        $this->seedMenuCategoryDefaults();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nova categoria'),
        ];
    }
}
