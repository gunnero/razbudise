<?php

namespace App\Filament\Resources\FinanceCategories;

use App\Filament\Resources\Concerns\HasLocalizedResourceLabels;
use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Filament\Resources\FinanceCategories\Pages\CreateFinanceCategory;
use App\Filament\Resources\FinanceCategories\Pages\EditFinanceCategory;
use App\Filament\Resources\FinanceCategories\Pages\ListFinanceCategories;
use App\Filament\Resources\FinanceCategories\Schemas\FinanceCategoryForm;
use App\Filament\Resources\FinanceCategories\Tables\FinanceCategoriesTable;
use App\Models\FinanceCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FinanceCategoryResource extends Resource
{
    use HasLocalizedResourceLabels;
    use HasResourcePermissions;

    protected static ?string $model = FinanceCategory::class;

    protected const string VIEW_PERMISSION = 'finance.view';

    protected const string MANAGE_PERMISSION = 'finance.manage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Categories';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return FinanceCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FinanceCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinanceCategories::route('/'),
            'create' => CreateFinanceCategory::route('/create'),
            'edit' => EditFinanceCategory::route('/{record}/edit'),
        ];
    }
}
