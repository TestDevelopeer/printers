<?php

namespace App\Filament\Resources\PrinterPollLogs\Schemas;

use App\Models\PrinterPollLog;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PrinterPollLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Итог опроса')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('status_label')
                            ->label('Результат')
                            ->badge()
                            ->color(fn (PrinterPollLog $record): string => $record->status_color),
                        TextEntry::make('source_label')
                            ->label('Источник')
                            ->badge(),
                        TextEntry::make('printer_status')
                            ->label('Статус принтера')
                            ->badge()
                            ->placeholder('Неизвестно'),
                        TextEntry::make('printer_name')
                            ->label('Принтер'),
                        TextEntry::make('printer_ip')
                            ->label('IP'),
                        TextEntry::make('duration_ms')
                            ->label('Длительность')
                            ->formatStateUsing(fn (int|float|null $state): string => $state === null ? '—' : sprintf('%d мс', (int) round($state))),
                        TextEntry::make('started_at')
                            ->label('Начало')
                            ->dateTime(),
                        TextEntry::make('finished_at')
                            ->label('Завершение')
                            ->dateTime()
                            ->placeholder('—'),
                        IconEntry::make('is_partial_response')
                            ->label('Частичный ответ')
                            ->boolean()
                            ->placeholder('—'),
                    ]),
                Section::make('Диагностика')
                    ->schema([
                        TextEntry::make('message')
                            ->label('Сообщение')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('exception_class')
                            ->label('Класс исключения')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make('Нормализованный результат')
                    ->schema([
                        TextEntry::make('normalized_payload_json')
                            ->label('')
                            ->state(fn (PrinterPollLog $record): string => self::formatJson($record->normalized_payload))
                            ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap'])
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
                Section::make('Сырой SNMP dump')
                    ->schema([
                        TextEntry::make('raw_snmp_dump_json')
                            ->label('')
                            ->state(fn (PrinterPollLog $record): string => self::formatJson($record->raw_snmp_dump))
                            ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap'])
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private static function formatJson(?array $data): string
    {
        if ($data === null || $data === []) {
            return '—';
        }

        $encoded = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $encoded === false ? '—' : $encoded;
    }
}
