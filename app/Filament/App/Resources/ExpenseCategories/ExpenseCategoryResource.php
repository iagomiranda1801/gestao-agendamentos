<?php

namespace App\Filament\App\Resources\ExpenseCategories;

use App\Filament\App\Resources\ExpenseCategories\Pages\CreateExpenseCategory;
use App\Filament\App\Resources\ExpenseCategories\Pages\EditExpenseCategory;
use App\Filament\App\Resources\ExpenseCategories\Pages\ListExpenseCategories;
use App\Filament\App\Resources\ExpenseCategories\Schemas\ExpenseCategoryForm;
use App\Filament\App\Resources\ExpenseCategories\Tables\ExpenseCategoriesTable;
use App\Models\ExpenseCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ExpenseCategoryResource extends Resource
{
    protected static ?string $model = ExpenseCategory::class;

    protected static ?string $slug = 'categorias-despesas';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Caixa';

    protected static ?string $modelLabel = 'categoria de despesa';

    protected static ?string $pluralModelLabel = 'categorias de despesas';

    protected static ?string $navigationLabel = 'Categorias de despesas';

    protected static ?int $navigationSort = 4;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return ExpenseCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpenseCategoriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseCategories::route('/'),
            'create' => CreateExpenseCategory::route('/create'),
            'edit' => EditExpenseCategory::route('/{record}/edit'),
        ];
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
