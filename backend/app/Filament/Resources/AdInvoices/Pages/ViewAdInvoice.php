<?php

namespace App\Filament\Resources\AdInvoices\Pages;

use App\Filament\Resources\AdInvoices\AdInvoiceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAdInvoice extends ViewRecord
{
    protected static string $resource = AdInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
