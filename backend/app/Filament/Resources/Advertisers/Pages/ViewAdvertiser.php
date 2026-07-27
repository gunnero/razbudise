<?php

namespace App\Filament\Resources\Advertisers\Pages;

use App\Filament\Resources\Advertisers\AdvertiserResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAdvertiser extends ViewRecord
{
    protected static string $resource = AdvertiserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
