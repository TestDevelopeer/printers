<?php

namespace App\Filament\Resources\PrinterPollLogs;

use App\Models\PrinterPollLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PrinterPollLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')
                    ->label('Начало')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('printer_name')
                    ->label('Принтер')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('printer_ip')
                    ->label('IP')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source_label')
                    ->label('Источник')
                    ->badge()
                    ->color(fn (PrinterPollLog $record): string => $record->source === 'manual' ? 'warning' : ($record->source === 'scheduled' ? 'info' : 'gray')),
                TextColumn::make('status_label')
                    ->label('Результат')
                    ->badge()
                    ->color(fn (PrinterPollLog $record): string => $record->status_color)
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('status', $direction)),
                TextColumn::make('printer_status')
                    ->label('Статус принтера')
                    ->badge()
                    ->placeholder('Неизвестно'),
                TextColumn::make('duration_ms')
                    ->label('Длительность')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : "{$state} мс")
                    ->sortable(),
                TextColumn::make('message')
                    ->label('Сообщение')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(120),
                TextColumn::make('finished_at')
                    ->label('Завершение')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Источник')
                    ->options([
                        'manual' => 'Ручной',
                        'scheduled' => 'По расписанию',
                        'cli' => 'CLI',
                    ]),
                SelectFilter::make('status')
                    ->label('Результат')
                    ->options([
                        'running' => 'Выполняется',
                        'success' => 'Успешно',
                        'offline' => 'Не в сети',
                        'error' => 'Ошибка',
                    ]),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
