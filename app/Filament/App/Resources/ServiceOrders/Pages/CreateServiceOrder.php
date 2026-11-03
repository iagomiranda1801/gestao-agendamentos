<?php

namespace App\Filament\App\Resources\ServiceOrders\Pages;

use App\Filament\App\Resources\Pages\CreateRecord;
use App\Filament\App\Resources\ServiceOrders\ServiceOrderResource;
use App\Services\ServiceOrders\ServiceOrderService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

class CreateServiceOrder extends CreateRecord
{
    protected static string $resource = ServiceOrderResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return 'Receber aparelho';
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(ServiceOrderService::class)->receive(Filament::getTenant(), auth()->user(), $data);
    }

    protected function getRedirectUrl(): string
    {
        return ServiceOrderResource::getUrl('edit', ['record' => $this->record]);
    }
}
