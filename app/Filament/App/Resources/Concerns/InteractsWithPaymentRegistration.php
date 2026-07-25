<?php

namespace App\Filament\App\Resources\Concerns;

use App\DataTransferObjects\Financial\PaymentData;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Financial\ReceivableService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

trait InteractsWithPaymentRegistration
{
    /**
     * @return array<int, Component>
     */
    protected static function paymentRegistrationFormSchema(?Receivable $receivable = null): array
    {
        return [
            TextInput::make('amount')
                ->label('Valor')
                ->numeric()
                ->minValue(0.01)
                ->step(0.01)
                ->prefix('R$')
                ->required()
                ->default(fn (): ?string => $receivable !== null
                    ? (string) $receivable->outstanding_amount
                    : null),
            TextInput::make('fee_amount')
                ->label('Taxa')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->prefix('R$')
                ->default('0.00'),
            Select::make('method')
                ->label('Forma de pagamento')
                ->options(PaymentMethod::options())
                ->required()
                ->native(false)
                ->default(PaymentMethod::Pix->value),
            Select::make('financial_account_id')
                ->label('Conta financeira')
                ->options(fn (): array => FinancialAccount::query()
                    ->where('company_id', Filament::getTenant()?->getKey())
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->required()
                ->searchable()
                ->native(false),
            DateTimePicker::make('paid_at')
                ->label('Pago em')
                ->default(now())
                ->required()
                ->native(false),
            Textarea::make('notes')
                ->label('Observações')
                ->rows(2),
        ];
    }

    protected static function makeRegisterPaymentAction(
        callable $resolveReceivable,
        ?callable $visible = null,
    ): Action {
        return Action::make('registerPayment')
            ->label('Registrar pagamento')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(function () use ($resolveReceivable, $visible): bool {
                if ($visible !== null && ! $visible()) {
                    return false;
                }

                $receivable = $resolveReceivable();

                return $receivable instanceof Receivable;
            })
            ->schema(fn (): array => self::paymentRegistrationFormSchema(
                ($resolveReceivable()) instanceof Receivable ? $resolveReceivable() : null,
            ))
            ->action(function (array $data) use ($resolveReceivable): void {
                self::processPaymentRegistration($resolveReceivable(), $data);
            });
    }

    protected static function makeRegisterPaymentTableAction(): Action
    {
        return Action::make('registerPayment')
            ->label('Registrar pagamento')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (Receivable $record): bool => auth()->user()?->can('registerPayment', $record) ?? false)
            ->schema(fn (Receivable $record): array => self::paymentRegistrationFormSchema($record))
            ->action(fn (Receivable $record, array $data): mixed => self::processPaymentRegistration($record, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function processPaymentRegistration(?Receivable $receivable, array $data): void
    {
        if (! $receivable instanceof Receivable) {
            return;
        }

        /** @var Company $company */
        $company = Filament::getTenant();
        /** @var User $user */
        $user = auth()->user();

        app(ReceivableService::class)->registerPayment(
            $company,
            $receivable,
            new PaymentData(
                amount: number_format((float) $data['amount'], 2, '.', ''),
                feeAmount: number_format((float) ($data['fee_amount'] ?? 0), 2, '.', ''),
                method: PaymentMethod::from($data['method']),
                paidAt: Carbon::parse($data['paid_at']),
                financialAccountId: (int) $data['financial_account_id'],
                notes: $data['notes'] ?? null,
            ),
            $user,
        );

        Notification::make()
            ->success()
            ->title('Pagamento registrado')
            ->send();
    }
}
