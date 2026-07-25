<?php

namespace App\Filament\App\Resources\Concerns;

use App\Models\Company;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Services\Stock\StockDocumentPostingService;
use App\Services\Stock\StockDocumentReversalService;
use App\Services\Stock\StockDocumentService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin EditRecord
 */
trait EditsStockDocument
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var StockDocument $record */
        $record = $this->getRecord();
        $record->loadMissing('items');

        $data['items'] = $record->items->map(fn (StockDocumentItem $item): array => [
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
            'unit_cost' => $item->unit_cost,
            'counted_quantity' => $item->counted_quantity,
        ])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        $items = $data['items'] ?? [];
        unset($data['items']);

        return app(StockDocumentService::class)->updateDraft($company, $record, $data, $items);
    }

    public function defaultForm(Schema $schema): Schema
    {
        $schema = parent::defaultForm($schema);

        /** @var StockDocument $record */
        $record = $this->getRecord();

        if ($record->isPosted() || $record->isReversed()) {
            return $schema
                ->disabled()
                ->operation('view');
        }

        return $schema;
    }

    protected function getFormActions(): array
    {
        /** @var StockDocument $record */
        $record = $this->getRecord();

        if (! $record->isDraft()) {
            return [];
        }

        return parent::getFormActions();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('post')
                ->label('Lançar')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Lançar documento')
                ->modalDescription('O documento será lançado e o estoque será atualizado. Esta ação não pode ser desfeita, apenas estornada.')
                ->visible(fn (): bool => $this->getRecord()->isDraft())
                ->authorize(fn (): bool => auth()->user()?->can('post', $this->getRecord()) ?? false)
                ->action(function (): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();

                    app(StockDocumentPostingService::class)->post(
                        $company,
                        $this->getRecord(),
                        auth()->user(),
                    );

                    Notification::make()
                        ->success()
                        ->title('Documento lançado')
                        ->send();

                    $this->record = $this->getRecord()->refresh();
                    $this->fillForm();
                }),
            Action::make('reverse')
                ->label('Estornar')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => $this->getRecord()->isPosted())
                ->authorize(fn (): bool => auth()->user()?->can('reverse', $this->getRecord()) ?? false)
                ->schema([
                    Textarea::make('reversal_reason')
                        ->label('Motivo do estorno')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();

                    app(StockDocumentReversalService::class)->reverse(
                        $company,
                        $this->getRecord(),
                        auth()->user(),
                        $data['reversal_reason'],
                    );

                    Notification::make()
                        ->success()
                        ->title('Documento estornado')
                        ->send();

                    $this->record = $this->getRecord()->refresh();
                    $this->fillForm();
                }),
        ];
    }
}
