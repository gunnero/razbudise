<?php

namespace App\Filament\Resources\AdInvoices;

use App\Filament\Resources\AdInvoices\Pages\CreateAdInvoice;
use App\Filament\Resources\AdInvoices\Pages\EditAdInvoice;
use App\Filament\Resources\AdInvoices\Pages\ListAdInvoices;
use App\Filament\Resources\AdInvoices\Pages\ViewAdInvoice;
use App\Filament\Resources\AdInvoices\Schemas\AdInvoiceForm;
use App\Filament\Resources\AdInvoices\Schemas\AdInvoiceInfolist;
use App\Filament\Resources\AdInvoices\Tables\AdInvoicesTable;
use App\Filament\Resources\Concerns\HasLocalizedResourceLabels;
use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Models\AdInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AdInvoiceResource extends Resource
{
    use HasLocalizedResourceLabels;
    use HasResourcePermissions;

    protected static ?string $model = AdInvoice::class;

    protected const string VIEW_PERMISSION = 'ads.view';

    protected const string MANAGE_PERMISSION = 'ads.manage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?string $navigationLabel = 'Ad Invoices';

    protected static ?int $navigationSort = 9;

    public static function form(Schema $schema): Schema
    {
        return AdInvoiceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AdInvoiceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdInvoicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdInvoices::route('/'),
            'create' => CreateAdInvoice::route('/create'),
            'view' => ViewAdInvoice::route('/{record}'),
            'edit' => EditAdInvoice::route('/{record}/edit'),
        ];
    }
}
