<?php

namespace App\Filament\App\Resources\Receivables\RelationManagers;

use App\Filament\App\Resources\Concerns\ConfiguresPaymentsRelationManager;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    use ConfiguresPaymentsRelationManager;

    protected static string $relationship = 'payments';

    protected static ?string $title = 'Pagamentos';

    protected static ?string $modelLabel = 'pagamento';

    protected static ?string $pluralModelLabel = 'pagamentos';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns($this->paymentTableColumns())
            ->defaultSort('paid_at', 'desc')
            ->headerActions([])
            ->recordActions([
                $this->cancelPaymentTableAction(),
            ])
            ->paginated([10, 25, 50]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
