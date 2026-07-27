<?php

namespace App\Filament\Resources\AdCustomers\Pages;

use App\Filament\Resources\AdCustomers\AdCustomerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdCustomers extends ListRecords
{
    protected static string $resource = AdCustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
