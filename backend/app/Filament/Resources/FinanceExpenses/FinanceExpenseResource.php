<?php

namespace App\Filament\Resources\FinanceExpenses;

use App\Filament\Resources\Concerns\HasLocalizedResourceLabels;
use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Filament\Resources\FinanceExpenses\Pages\CreateFinanceExpense;
use App\Filament\Resources\FinanceExpenses\Pages\EditFinanceExpense;
use App\Filament\Resources\FinanceExpenses\Pages\ListFinanceExpenses;
use App\Filament\Resources\FinanceExpenses\Pages\ViewFinanceExpense;
use App\Filament\Resources\FinanceExpenses\Schemas\FinanceExpenseForm;
use App\Filament\Resources\FinanceExpenses\Schemas\FinanceExpenseInfolist;
use App\Filament\Resources\FinanceExpenses\Tables\FinanceExpensesTable;
use App\Models\FinanceExpense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FinanceExpenseResource extends Resource
{
    use HasLocalizedResourceLabels;
    use HasResourcePermissions;

    protected static ?string $model = FinanceExpense::class;

    protected const string VIEW_PERMISSION = 'finance.view';

    protected const string MANAGE_PERMISSION = 'finance.manage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Expenses';

    protected static ?int $navigationSort = 32;

    public static function form(Schema $schema): Schema
    {
        return FinanceExpenseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FinanceExpenseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FinanceExpensesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinanceExpenses::route('/'),
            'create' => CreateFinanceExpense::route('/create'),
            'view' => ViewFinanceExpense::route('/{record}'),
            'edit' => EditFinanceExpense::route('/{record}/edit'),
        ];
    }
}
