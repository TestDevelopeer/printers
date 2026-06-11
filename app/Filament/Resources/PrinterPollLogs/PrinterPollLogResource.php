<?php

namespace App\Filament\Resources\PrinterPollLogs;

use App\Filament\Resources\PrinterPollLogs\Pages\ListPrinterPollLogs;
use App\Filament\Resources\PrinterPollLogs\Pages\ViewPrinterPollLog;
use App\Filament\Resources\PrinterPollLogs\Schemas\PrinterPollLogInfolist;
use App\Models\PrinterPollLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PrinterPollLogResource extends Resource
{
    protected static ?string $model = PrinterPollLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Логи опроса';

    protected static ?string $modelLabel = 'Лог опроса';

    protected static ?string $pluralModelLabel = 'Логи опроса';

    protected static ?int $navigationSort = 20;

    public static function infolist(Schema $schema): Schema
    {
        return PrinterPollLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PrinterPollLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrinterPollLogs::route('/'),
            'view' => ViewPrinterPollLog::route('/{record}'),
        ];
    }
}
