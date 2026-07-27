<?php

namespace App\Filament\Resources\FinanceInvoices\Pages;

use App\Filament\Resources\FinanceInvoices\FinanceInvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFinanceInvoices extends ListRecords
{
    protected static string $resource = FinanceInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
