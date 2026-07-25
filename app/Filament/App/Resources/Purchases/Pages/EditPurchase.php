<?php

namespace App\Filament\App\Resources\Purchases\Pages;

use App\Filament\App\Resources\Concerns\EditsStockDocument;
use App\Filament\App\Resources\Purchases\PurchaseResource;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\Payable;
use App\Models\StockDocument;
use App\Services\Financial\PayableService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPurchase extends EditRecord
{
    use EditsStockDocument;

    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            Action::make('generatePayable')
                ->label('Gerar conta a pagar')
                ->icon('heroicon-o-credit-card')
                ->color('primary')
                ->visible(fn (): bool => $this->canGeneratePayable())
                ->schema([
                    Select::make('expense_category_id')
                        ->label('Categoria de despesa')
                        ->options(fn (): array => ExpenseCategory::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn (): ?int => ExpenseCategory::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->where('type', 'stock_purchase')
                            ->value('id'))
                        ->required()
                        ->native(false),
                    DatePicker::make('issue_date')
                        ->label('Data de emissão')
                        ->default(now())
                        ->required()
                        ->native(false),
                    DatePicker::make('competence_date')
                        ->label('Data de competência')
                        ->default(now())
                        ->required()
                        ->native(false),
                    TextInput::make('installment_count')
                        ->label('Número de parcelas')
                        ->numeric()
                        ->default(1)
                        ->minValue(1)
                        ->required(),
                    DatePicker::make('first_due_date')
                        ->label('Primeiro vencimento')
                        ->default(now()->addDays(30))
                        ->required()
                        ->native(false),
                    TextInput::make('installment_interval_days')
                        ->label('Intervalo entre parcelas (dias)')
                        ->numeric()
                        ->default(30)
                        ->minValue(1)
                        ->required(),
                    Textarea::make('notes')
                        ->label('Observação')
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();

                    /** @var StockDocument $record */
                    $record = $this->getRecord();

                    $category = ExpenseCategory::query()
                        ->where('company_id', $company->getKey())
                        ->findOrFail($data['expense_category_id']);

                    app(PayableService::class)->createFromStockPurchase(
                        $company,
                        $record,
                        $category,
                        auth()->user(),
                        [
                            'issue_date' => $data['issue_date'],
                            'competence_date' => $data['competence_date'],
                            'installment_count' => $data['installment_count'],
                            'first_due_date' => $data['first_due_date'],
                            'installment_interval_days' => $data['installment_interval_days'],
                            'notes' => $data['notes'] ?? null,
                        ],
                    );

                    Notification::make()
                        ->success()
                        ->title('Conta a pagar gerada')
                        ->send();
                }),
        ];
    }

    protected function canGeneratePayable(): bool
    {
        /** @var StockDocument $record */
        $record = $this->getRecord();

        if (! $record->isPosted()) {
            return false;
        }

        if (bccomp((string) $record->total_amount, '0', 2) <= 0) {
            return false;
        }

        return ! Payable::query()->where('stock_document_id', $record->getKey())->exists();
    }
}
