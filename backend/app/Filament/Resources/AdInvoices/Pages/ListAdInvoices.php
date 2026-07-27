<?php

namespace App\Filament\Resources\AdInvoices\Pages;

use App\Filament\Resources\AdInvoices\AdInvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdInvoices extends ListRecords
{
    protected static string $resource = AdInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
