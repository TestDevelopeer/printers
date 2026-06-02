<?php

namespace App\Filament\Resources\Printers\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use App\Models\TonerSupply;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;

class PrinterInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Тонеры')
                    ->schema([
                        RepeatableEntry::make('tonerSupplies')
                            ->label('')
                            ->contained(false)
                            ->placeholder('Активные тонеры не найдены')
                            ->schema([
                                TextEntry::make('color_label')
                                    ->label('Цвет')
                                    ->state(fn (TonerSupply $record): string => $record->color_label),
                                TextEntry::make('snmp_description')
                                    ->label('Описание')
                                    ->placeholder('Неизвестно'),
                                ViewEntry::make('percentage')
                                    ->label('Уровень')
                                    ->view('filament.infolists.entries.toner-progress'),
                                TextEntry::make('level')
                                    ->label('Значение')
                                    ->placeholder('Неизвестно'),
                                TextEntry::make('max_capacity')
                                    ->label('Емкость')
                                    ->placeholder('Неизвестно'),
                                TextEntry::make('status_label')
                                    ->label('Статус')
                                    ->state(fn (TonerSupply $record): string => $record->status_label)
                                    ->badge()
                                    ->color(fn (TonerSupply $record): string => $record->isLow() ? 'danger' : ($record->percentage === null ? 'warning' : 'success')),
                                TextEntry::make('service_status_label')
                                    ->label('Обслуживание')
                                    ->badge()
                                    ->color(fn (TonerSupply $record): string => $record->is_on_service ? 'warning' : 'success'),
                                TextEntry::make('comment_display')
                                    ->label('Комментарий')
                                    ->placeholder('Без комментария')
                                    ->columnSpanFull()
                                    ->suffixAction(self::editSupplyMetadataAction('edit_active_supply_metadata')),
                            ])
                            ->columns(2),
                    ])
                    ->collapsible()
                    ->collapsed(false),
                Section::make('История картриджей')
                    ->schema([
                        RepeatableEntry::make('tonerHistory')
                            ->label('')
                            ->contained(false)
                            ->placeholder('История картриджей пуста')
                            ->schema([
                                TextEntry::make('color_label')
                                    ->label('Цвет')
                                    ->state(fn (TonerSupply $record): string => $record->color_label),
                                TextEntry::make('snmp_description')
                                    ->label('Описание')
                                    ->placeholder('Неизвестно'),
                                TextEntry::make('percentage_display')
                                    ->label('Последний уровень')
                                    ->state(fn (TonerSupply $record): string => $record->percentage_display),
                                TextEntry::make('last_seen_at')
                                    ->label('Последний раз в принтере')
                                    ->dateTime()
                                    ->placeholder('Неизвестно'),
                                TextEntry::make('removed_at')
                                    ->label('Перемещен в историю')
                                    ->dateTime()
                                    ->placeholder('Неизвестно'),
                                TextEntry::make('service_status_label')
                                    ->label('Обслуживание')
                                    ->badge()
                                    ->color(fn (TonerSupply $record): string => $record->is_on_service ? 'warning' : 'success'),
                                TextEntry::make('comment_display')
                                    ->label('Комментарий')
                                    ->placeholder('Без комментария')
                                    ->columnSpanFull()
                                    ->suffixAction(self::editSupplyMetadataAction('edit_history_supply_metadata')),
                            ])
                            ->columns(2),
                    ])
                    ->collapsible()
                    ->collapsed(),
                Section::make('Общие')
                    ->schema([
                        TextEntry::make('display_name')->label('Имя'),
                        TextEntry::make('discovered_name')->label('Обнаруженное имя')->placeholder('Не обнаружено'),
                        TextEntry::make('manufacturer')->label('Производитель')->placeholder('Неизвестно'),
                        TextEntry::make('model')->label('Модель')->placeholder('Неизвестно'),
                        TextEntry::make('serial_number')->label('Серийный номер')->placeholder('Неизвестно'),
                        TextEntry::make('location')->label('Расположение')->placeholder('Неизвестно'),
                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state?->label() ?? 'Неизвестно')
                            ->color(fn ($state) => $state?->color() ?? 'warning'),
                        TextEntry::make('last_error')->label('Последняя ошибка')->placeholder('Ошибок нет'),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(),
                Section::make('Сеть')
                    ->schema([
                        TextEntry::make('ip_address')->label('IP-адрес'),
                        TextEntry::make('mac_address')->label('MAC-адрес')->placeholder('Неизвестно'),
                        TextEntry::make('hostname')->label('Хост')->placeholder('Неизвестно'),
                        TextEntry::make('snmp_version')->label('SNMP версия'),
                        TextEntry::make('snmp_community')->label('SNMP community'),
                        TextEntry::make('last_seen_at')->label('Последний успешный ответ')->dateTime()->placeholder('Никогда'),
                        TextEntry::make('last_polled_at')->label('Последний опрос')->dateTime()->placeholder('Никогда'),
                        IconEntry::make('is_active')->label('Активен')->boolean(),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    private static function editSupplyMetadataAction(string $name): Action
    {
        return Action::make($name)
            ->icon('heroicon-m-pencil-square')
            ->label('Изменить')
            ->modalHeading('Комментарий и обслуживание')
            ->schema([
                Textarea::make('comment')
                    ->label('Комментарий')
                    ->rows(3),
                Toggle::make('is_on_service')
                    ->label('Отправлен на обслуживание'),
            ])
            ->fillForm(fn (TonerSupply $record): array => [
                'comment' => $record->comment,
                'is_on_service' => $record->is_on_service,
            ])
            ->action(function (TonerSupply $record, array $data): void {
                $record->update([
                    'comment' => filled($data['comment'] ?? null) ? trim((string) $data['comment']) : null,
                    'is_on_service' => (bool) ($data['is_on_service'] ?? false),
                ]);

                Notification::make()
                    ->title('Карточка картриджа обновлена')
                    ->success()
                    ->send();
            });
    }
}
