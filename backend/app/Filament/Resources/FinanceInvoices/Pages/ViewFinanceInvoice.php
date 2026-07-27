<?php

namespace App\Filament\Resources\FinanceInvoices\Pages;

use App\Filament\Resources\FinanceInvoices\FinanceInvoiceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFinanceInvoice extends ViewRecord
{
    protected static string $resource = FinanceInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
