<?php

namespace App\Filament\Resources\FinanceInvoices;

use App\Filament\Resources\Concerns\HasLocalizedResourceLabels;
use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Filament\Resources\FinanceInvoices\Pages\CreateFinanceInvoice;
use App\Filament\Resources\FinanceInvoices\Pages\EditFinanceInvoice;
use App\Filament\Resources\FinanceInvoices\Pages\ListFinanceInvoices;
use App\Filament\Resources\FinanceInvoices\Pages\ViewFinanceInvoice;
use App\Filament\Resources\FinanceInvoices\Schemas\FinanceInvoiceForm;
use App\Filament\Resources\FinanceInvoices\Schemas\FinanceInvoiceInfolist;
use App\Filament\Resources\FinanceInvoices\Tables\FinanceInvoicesTable;
use App\Models\FinanceInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FinanceInvoiceResource extends Resource
{
    use HasLocalizedResourceLabels;
    use HasResourcePermissions;

    protected static ?string $model = FinanceInvoice::class;

    protected const string VIEW_PERMISSION = 'finance.view';

    protected const string MANAGE_PERMISSION = 'finance.manage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Invoices';

    protected static ?int $navigationSort = 31;

    public static function form(Schema $schema): Schema
    {
        return FinanceInvoiceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FinanceInvoiceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FinanceInvoicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinanceInvoices::route('/'),
            'create' => CreateFinanceInvoice::route('/create'),
            'view' => ViewFinanceInvoice::route('/{record}'),
            'edit' => EditFinanceInvoice::route('/{record}/edit'),
        ];
    }
}
