<?php

namespace App\Filament\Resources\AdCustomers;

use App\Filament\Resources\AdCustomers\Pages\CreateAdCustomer;
use App\Filament\Resources\AdCustomers\Pages\EditAdCustomer;
use App\Filament\Resources\AdCustomers\Pages\ListAdCustomers;
use App\Filament\Resources\AdCustomers\Pages\ViewAdCustomer;
use App\Filament\Resources\AdCustomers\Schemas\AdCustomerForm;
use App\Filament\Resources\AdCustomers\Schemas\AdCustomerInfolist;
use App\Filament\Resources\AdCustomers\Tables\AdCustomersTable;
use App\Filament\Resources\Concerns\HasLocalizedResourceLabels;
use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Models\AdCustomer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AdCustomerResource extends Resource
{
    use HasLocalizedResourceLabels;
    use HasResourcePermissions;

    protected static ?string $model = AdCustomer::class;

    protected const string VIEW_PERMISSION = 'ads.view';

    protected const string MANAGE_PERMISSION = 'ads.manage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|\UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?string $navigationLabel = 'Ad Customers';

    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return AdCustomerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AdCustomerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdCustomersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdCustomers::route('/'),
            'create' => CreateAdCustomer::route('/create'),
            'view' => ViewAdCustomer::route('/{record}'),
            'edit' => EditAdCustomer::route('/{record}/edit'),
        ];
    }
}
