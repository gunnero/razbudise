<?php

namespace App\Filament\Resources\WordpressImportRecords;

use App\Filament\Resources\Concerns\HasLocalizedResourceLabels;
use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Filament\Resources\WordpressImportRecords\Pages\CreateWordpressImportRecord;
use App\Filament\Resources\WordpressImportRecords\Pages\EditWordpressImportRecord;
use App\Filament\Resources\WordpressImportRecords\Pages\ListWordpressImportRecords;
use App\Filament\Resources\WordpressImportRecords\Pages\ViewWordpressImportRecord;
use App\Filament\Resources\WordpressImportRecords\Schemas\WordpressImportRecordForm;
use App\Filament\Resources\WordpressImportRecords\Schemas\WordpressImportRecordInfolist;
use App\Filament\Resources\WordpressImportRecords\Tables\WordpressImportRecordsTable;
use App\Models\WordpressImportRecord;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WordpressImportRecordResource extends Resource
{
    use HasLocalizedResourceLabels;
    use HasResourcePermissions;

    protected static ?string $model = WordpressImportRecord::class;

    protected const string VIEW_PERMISSION = 'imports.view';

    protected const string MANAGE_PERMISSION = 'imports.manage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Migration';

    protected static ?string $navigationLabel = 'WordPress Imports';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return WordpressImportRecordForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WordpressImportRecordInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WordpressImportRecordsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWordpressImportRecords::route('/'),
            'create' => CreateWordpressImportRecord::route('/create'),
            'view' => ViewWordpressImportRecord::route('/{record}'),
            'edit' => EditWordpressImportRecord::route('/{record}/edit'),
        ];
    }
}
