<?php

namespace App\Filament\App\Resources\Payables\Pages;

use App\Filament\App\Pages\RegisterExpensePage;
use App\Filament\App\Resources\Payables\PayableResource;
use App\Policies\PayablePolicy;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPayables extends ListRecords
{
    protected static string $resource = PayableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('registerExpense')
                ->label('Registrar despesa')
                ->icon('heroicon-o-receipt-percent')
                ->url(RegisterExpensePage::getUrl())
                ->visible(fn (): bool => (new PayablePolicy)->create(auth()->user())),
        ];
    }
}
