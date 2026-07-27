<?php

namespace App\Filament\Resources\WordpressImportRecords\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WordpressImportRecordForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Import record')
                    ->schema([
                        TextInput::make('wordpress_id')->required()->integer(),
                        TextInput::make('wordpress_type')->required()->default('post')->maxLength(255),
                        Select::make('status')
                            ->required()
                            ->options([
                                'pending' => 'Pending',
                                'imported' => 'Imported',
                                'failed' => 'Failed',
                                'skipped' => 'Skipped',
                            ])
                            ->default('pending'),
                        TextInput::make('old_url')->url()->maxLength(255),
                        TextInput::make('new_url')->maxLength(255),
                        DateTimePicker::make('imported_at'),
                        KeyValue::make('warnings')->columnSpanFull(),
                        Textarea::make('error_message')->rows(4)->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Source payload')
                    ->schema([
                        KeyValue::make('source_payload')->disabled()->dehydrated(false),
                    ])
                    ->collapsible(),
            ]);
    }
}
