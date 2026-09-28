<?php

namespace App\Filament\App\Resources\Clients\RelationManagers;

use App\Filament\App\Resources\TattooRequests\TattooRequestResource;
use App\Models\Company;
use App\Models\TattooRequest;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TattooRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'tattooRequests';

    protected static ?string $title = 'Orçamentos de tatuagem';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $company = Filament::getTenant();

        return parent::canViewForRecord($ownerRecord, $pageClass)
            && $company instanceof Company && $company->isTattooStudio()
            && TattooRequestResource::canManageRequests();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Recebido')->dateTime('d/m/Y H:i'),
            TextColumn::make('description')->label('Desenho')->limit(60),
            TextColumn::make('status')->label('Status')->formatStateUsing(fn (string $state) => TattooRequestResource::statuses()[$state] ?? $state),
        ])->recordActions([
            Action::make('open')->label('Abrir')->url(fn (TattooRequest $record) => TattooRequestResource::getUrl('edit', ['record' => $record]))
                ->visible(fn (TattooRequest $record) => auth()->user()?->can('view', $record) ?? false),
        ])->headerActions([])->defaultSort('created_at', 'desc');
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
