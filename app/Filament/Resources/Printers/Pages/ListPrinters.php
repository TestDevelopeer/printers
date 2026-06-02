<?php

namespace App\Filament\Resources\Printers\Pages;

use App\Filament\Pages\NetworkScan;
use App\Filament\Resources\Printers\PrinterResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPrinters extends ListRecords
{
    protected static string $resource = PrinterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('networkScan')
                ->label('Network Scan')
                ->icon('heroicon-m-magnifying-glass')
                ->url(NetworkScan::getUrl()),
            CreateAction::make(),
        ];
    }
}
