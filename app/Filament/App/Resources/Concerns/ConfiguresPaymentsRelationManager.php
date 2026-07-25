<?php

namespace App\Filament\App\Resources\Concerns;

use App\Models\Company;
use App\Models\Payment;
use App\Models\User;
use App\Services\Financial\ReceivableService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Gate;

trait ConfiguresPaymentsRelationManager
{
    /**
     * @return array<int, Column>
     */
    protected function paymentTableColumns(): array
    {
        return [
            TextColumn::make('paid_at')
                ->label('Pago em')
                ->dateTime('d/m/Y H:i')
                ->sortable(),
            TextColumn::make('method')
                ->label('Forma')
                ->formatStateUsing(fn ($state) => $state->label()),
            TextColumn::make('amount')
                ->label('Valor')
                ->money('BRL', locale: 'pt_BR'),
            TextColumn::make('fee_amount')
                ->label('Taxa')
                ->money('BRL', locale: 'pt_BR'),
            TextColumn::make('net_amount')
                ->label('Valor líquido')
                ->money('BRL', locale: 'pt_BR'),
            TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn ($state) => $state->label()),
            TextColumn::make('registrar.name')
                ->label('Registrado por')
                ->placeholder('—'),
            TextColumn::make('notes')
                ->label('Observações')
                ->placeholder('—')
                ->toggleable(),
        ];
    }

    protected function cancelPaymentTableAction(): Action
    {
        return Action::make('cancelPayment')
            ->label('Cancelar pagamento')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Payment $record): bool => Gate::allows('cancel', $record))
            ->schema([
                Textarea::make('cancellation_reason')
                    ->label('Motivo do cancelamento')
                    ->rows(3),
            ])
            ->action(function (Payment $record, array $data): void {
                /** @var Company $company */
                $company = Filament::getTenant();
                /** @var User $user */
                $user = auth()->user();

                app(ReceivableService::class)->cancelPayment(
                    $company,
                    $record,
                    $user,
                    $data['cancellation_reason'] ?? null,
                );

                Notification::make()
                    ->success()
                    ->title('Pagamento cancelado')
                    ->send();
            });
    }
}
