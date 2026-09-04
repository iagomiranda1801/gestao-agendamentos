<?php

namespace App\Filament\Admin\Resources\PlatformInvoices;

use App\Enums\PlatformInvoiceStatus;
use App\Models\Company;
use App\Models\PlatformInvoice;
use App\Services\Company\CompanySubscriptionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class PlatformInvoiceActions
{
    public static function issue(?Company $company = null): Action
    {
        $action = Action::make('issueInvoice')
            ->label('Gerar fatura')
            ->icon('heroicon-o-document-plus')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Gerar fatura')
            ->modalDescription('A fatura usa os módulos e o ciclo atuais da empresa. Só pode haver uma fatura aberta ou vencida por vez.');

        if ($company === null) {
            $action->schema([
                Select::make('company_id')
                    ->label('Empresa')
                    ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->native(false),
            ]);
        }

        return $action->action(function (array $data) use ($company): void {
            $target = $company ?? Company::query()->find($data['company_id'] ?? null);

            if (! $target instanceof Company) {
                Notification::make()->danger()->title('Empresa não encontrada.')->send();

                return;
            }

            try {
                $invoice = app(CompanySubscriptionService::class)->issueInvoice($target);
            } catch (ValidationException $exception) {
                self::notifyError($exception);

                return;
            }

            Notification::make()
                ->success()
                ->title('Fatura gerada')
                ->body($invoice->number.' · '.app(CompanySubscriptionService::class)->formatReais($invoice->amount_cents))
                ->send();
        });
    }

    public static function pay(): Action
    {
        return Action::make('pay')
            ->label('Marcar como paga')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Confirmar pagamento')
            ->modalDescription('O PIX/boleto foi recebido? A assinatura será renovada pelo ciclo desta fatura.')
            ->visible(fn (PlatformInvoice $record): bool => $record->isOutstanding())
            ->action(function (PlatformInvoice $record): void {
                try {
                    app(CompanySubscriptionService::class)->payInvoice($record);
                } catch (ValidationException $exception) {
                    self::notifyError($exception);

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Fatura paga')
                    ->body('A assinatura foi renovada.')
                    ->send();
            });
    }

    public static function markOverdue(): Action
    {
        return Action::make('markOverdue')
            ->label('Marcar como vencida')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (PlatformInvoice $record): bool => $record->status === PlatformInvoiceStatus::Open)
            ->action(function (PlatformInvoice $record): void {
                try {
                    app(CompanySubscriptionService::class)->markInvoiceOverdue($record);
                } catch (ValidationException $exception) {
                    self::notifyError($exception);

                    return;
                }

                Notification::make()->success()->title('Fatura marcada como vencida.')->send();
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label('Cancelar')
            ->icon('heroicon-o-x-circle')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (PlatformInvoice $record): bool => $record->isOutstanding())
            ->action(function (PlatformInvoice $record): void {
                try {
                    app(CompanySubscriptionService::class)->cancelInvoice($record);
                } catch (ValidationException $exception) {
                    self::notifyError($exception);

                    return;
                }

                Notification::make()->success()->title('Fatura cancelada.')->send();
            });
    }

    protected static function notifyError(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('Não foi possível atualizar a fatura')
            ->body(collect($exception->errors())->flatten()->implode(' '))
            ->send();
    }
}
