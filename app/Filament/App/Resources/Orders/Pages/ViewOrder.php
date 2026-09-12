<?php

namespace App\Filament\App\Resources\Orders\Pages;

use App\Filament\App\Resources\Orders\OrderResource;
use App\Models\Company;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('advance')
                ->label(fn (Order $record): string => $record->nextStatus()?->label() ?? 'Avançar')
                ->visible(fn (Order $record): bool => $record->canAdvance() && (auth()->user()?->can('advance', $record) ?? false))
                ->action(function (Order $record): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();
                    $updated = app(OrderService::class)->advance($company, $record, auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Pedido atualizado')
                        ->body($updated->displayNumber().' agora está '.$updated->status->label())
                        ->send();
                }),
            Action::make('cancel')
                ->label('Cancelar')
                ->color('danger')
                ->visible(fn (Order $record): bool => $record->canCancel() && (auth()->user()?->can('cancel', $record) ?? false))
                ->schema([
                    Textarea::make('reason')
                        ->label('Motivo')
                        ->required()
                        ->maxLength(500),
                ])
                ->action(function (Order $record, array $data): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();
                    app(OrderService::class)->cancel($company, $record, (string) $data['reason'], auth()->user());

                    Notification::make()->success()->title('Pedido cancelado')->send();
                }),
        ];
    }
}
