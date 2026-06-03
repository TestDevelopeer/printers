<?php

namespace App\Filament\Resources\PrinterPollLogs\Pages;

use App\Filament\Resources\PrinterPollLogs\PrinterPollLogResource;
use Filament\Resources\Pages\ListRecords;

class ListPrinterPollLogs extends ListRecords
{
    protected static string $resource = PrinterPollLogResource::class;
}
