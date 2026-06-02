<?php

namespace App\Filament\Resources\CartridgeSets\Pages;

use App\Filament\Resources\CartridgeSets\CartridgeSetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCartridgeSets extends ListRecords
{
    protected static string $resource = CartridgeSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
