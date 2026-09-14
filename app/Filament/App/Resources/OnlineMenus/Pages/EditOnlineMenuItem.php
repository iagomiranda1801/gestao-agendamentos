<?php

namespace App\Filament\App\Resources\OnlineMenus\Pages;

use App\Filament\App\Concerns\FillsProductVariantForm;
use App\Filament\App\Resources\OnlineMenus\OnlineMenuResource;
use App\Filament\App\Resources\Pages\EditRecord;
use App\Models\Company;
use App\Models\Product;
use App\Services\Product\ProductService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

class EditOnlineMenuItem extends EditRecord
{
    use FillsProductVariantForm;

    protected static string $resource = OnlineMenuResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillProductVariants($data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        /** @var Product $record */
        return app(ProductService::class)->update($company, $record, $data);
    }
}
