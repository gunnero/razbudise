<?php

namespace App\Filament\Resources\AdBookingRequests\Pages;

use App\Filament\Resources\AdBookingRequests\AdBookingRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdBookingRequests extends ListRecords
{
    protected static string $resource = AdBookingRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
