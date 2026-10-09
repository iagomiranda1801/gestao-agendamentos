<?php

namespace App\Filament\Admin\Resources\SegmentSettings;

use App\Filament\Admin\Resources\SegmentSettings\Pages\EditSegmentSetting;
use App\Filament\Admin\Resources\SegmentSettings\Pages\ListSegmentSettings;
use App\Filament\Admin\Resources\SegmentSettings\Schemas\SegmentSettingForm;
use App\Filament\Admin\Resources\SegmentSettings\Tables\SegmentSettingsTable;
use App\Models\SegmentSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SegmentSettingResource extends Resource
{
    protected static ?string $model = SegmentSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static ?string $modelLabel = 'produto';

    protected static ?string $pluralModelLabel = 'produtos';

    protected static ?string $navigationLabel = 'Produtos';

    protected static string|UnitEnum|null $navigationGroup = 'Gestão de Agendamentos';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return SegmentSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SegmentSettingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSegmentSettings::route('/'),
            'edit' => EditSegmentSetting::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
