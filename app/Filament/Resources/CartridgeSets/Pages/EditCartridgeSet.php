<?php

namespace App\Filament\Resources\CartridgeSets\Pages;

use App\Filament\Resources\CartridgeSets\CartridgeSetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCartridgeSet extends EditRecord
{
    protected static string $resource = CartridgeSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
