<?php

namespace App\Filament\App\Resources\Suppliers\Pages;

use App\Filament\App\Resources\Suppliers\SupplierResource;
use App\Models\Company;
use App\Services\Supplier\SupplierService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(SupplierService::class)->create($company, $data);
    }
}
