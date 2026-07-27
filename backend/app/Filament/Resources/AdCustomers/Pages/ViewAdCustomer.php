<?php

namespace App\Filament\Resources\AdCustomers\Pages;

use App\Filament\Resources\AdCustomers\AdCustomerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAdCustomer extends ViewRecord
{
    protected static string $resource = AdCustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
