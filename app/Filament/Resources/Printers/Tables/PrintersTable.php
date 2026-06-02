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
            ->columns([
                TextColumn::make('display_name')
                    ->label('Name')
                    ->searchable(['name', 'discovered_name', 'hostname', 'ip_address'])
                    ->sortable(),
                TextColumn::make('ip_address')
                    ->label('IP')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('model')
                    ->placeholder('Unknown')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? 'Unknown')
                    ->color(fn ($state) => $state?->color() ?? 'warning')
                    ->sortable(),
                TextColumn::make('toner_summary')
                    ->label('Toner')
                    ->state(fn (Printer $record): string => $record->toner_summary),
                IconColumn::make('has_low_toner')
                    ->label('Low')
                    ->boolean()
                    ->state(fn (Printer $record): bool => $record->has_low_toner),
                TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_polled_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'online' => 'Online',
                        'offline' => 'Offline',
                        'error' => 'Error',
                        'unknown' => 'Unknown',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('Active'),
                TernaryFilter::make('low_toner')
                    ->label('Low toner')
                    ->queries(
                        true: fn ($query) => $query->whereHas('tonerSupplies', fn ($tonerQuery) => $tonerQuery->whereNotNull('percentage')->where('percentage', '<=', config('printers.low_toner_threshold', 15))),
                        false: fn ($query) => $query->whereDoesntHave('tonerSupplies', fn ($tonerQuery) => $tonerQuery->whereNotNull('percentage')->where('percentage', '<=', config('printers.low_toner_threshold', 15))),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('rename')
                    ->icon('heroicon-m-pencil-square')
                    ->schema([
                        TextInput::make('name')
                            ->label('Display name')
                            ->required(),
                    ])
                    ->fillForm(fn (Printer $record): array => [
                        'name' => $record->name ?: $record->display_name,
                    ])
                    ->action(function (Printer $record, array $data): void {
                        $record->update(['name' => $data['name']]);
                    }),
                Action::make('poll')
                    ->icon('heroicon-m-arrow-path')
                    ->requiresConfirmation()
                    ->action(function (Printer $record): void {
                        PollPrinterJob::dispatch($record->id);

                        Notification::make()
                            ->title('Polling queued')
                            ->body("Printer {$record->display_name} will be polled in the background.")
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
