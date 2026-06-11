<?php

namespace App\Filament\Resources\Printers\Tables;

use App\Jobs\PollPrinterJob;
use App\Models\Printer;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PrintersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->columns([
                TextColumn::make('display_name')
                    ->label('Имя')
                    ->searchable(['name', 'discovered_name', 'hostname', 'ip_address'])
                    ->sortable(),
                TextColumn::make('ip_address')
                    ->label('IP')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('model')
                    ->label('Модель')
                    ->placeholder('Неизвестно')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? 'Неизвестно')
                    ->color(fn ($state) => $state?->color() ?? 'warning')
                    ->sortable(),
                TextColumn::make('toner_summary')
                    ->label('Тонеры')
                    ->state(fn (Printer $record): string => $record->toner_summary),
                IconColumn::make('has_low_toner')
                    ->label('Низкий')
                    ->boolean()
                    ->state(fn (Printer $record): bool => $record->has_low_toner),
                IconColumn::make('is_polling')
                    ->label('Фон')
                    ->boolean(),
                TextColumn::make('last_seen_at')
                    ->label('Последний ответ')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_polled_at')
                    ->label('Последний опрос')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'online' => 'В сети',
                        'offline' => 'Не в сети',
                        'error' => 'Ошибка',
                        'unknown' => 'Неизвестно',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('Активен'),
                TernaryFilter::make('low_toner')
                    ->label('Низкий тонер')
                    ->queries(
                        true: fn ($query) => $query->whereHas('tonerSupplies', fn ($tonerQuery) => $tonerQuery->whereNotNull('percentage')->where('percentage', '<=', config('printers.low_toner_threshold', 15))),
                        false: fn ($query) => $query->whereDoesntHave('tonerSupplies', fn ($tonerQuery) => $tonerQuery->whereNotNull('percentage')->where('percentage', '<=', config('printers.low_toner_threshold', 15))),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('rename')
                    ->label('Переименовать')
                    ->icon('heroicon-m-pencil-square')
                    ->schema([
                        TextInput::make('name')
                            ->label('Отображаемое имя')
                            ->required(),
                    ])
                    ->fillForm(fn (Printer $record): array => [
                        'name' => $record->name ?: $record->display_name,
                    ])
                    ->action(function (Printer $record, array $data): void {
                        $record->update(['name' => $data['name']]);
                    }),
                Action::make('poll')
                    ->label(fn (Printer $record): string => $record->is_polling ? 'Опрос выполняется' : 'Опросить')
                    ->icon('heroicon-m-arrow-path')
                    ->color(fn (Printer $record): string => $record->is_polling ? 'warning' : 'gray')
                    ->requiresConfirmation()
                    ->disabled(fn (Printer $record): bool => (bool) $record->is_polling)
                    ->action(function (Printer $record): void {
                        $record->forceFill([
                            'is_polling' => true,
                            'manual_poll_requested_at' => now(),
                        ])->save();

                        PollPrinterJob::dispatch($record->id, 'manual');

                        Notification::make()
                            ->title('Опрос поставлен в очередь')
                            ->body("Принтер {$record->display_name} опрашивается в фоне.")
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
