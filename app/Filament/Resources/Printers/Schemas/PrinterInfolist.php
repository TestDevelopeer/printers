<?php

namespace App\Filament\Resources\Printers\Schemas;

use App\Enums\TonerColor;
use App\Models\Printer;
use App\Models\TonerSupply;
use App\Services\Printers\TonerSupplyIdentityService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use InvalidArgumentException;

class PrinterInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Фоновый опрос')
                    ->poll(fn (Printer $record): ?string => $record->is_polling ? '5s' : null)
                    ->visible(fn (Printer $record): bool => (bool) $record->is_polling)
                    ->schema([
                        TextEntry::make('manual_poll_requested_at')
                            ->label('Состояние')
                            ->state(function (Printer $record): string {
                                if ($record->manual_poll_requested_at === null) {
                                    return 'Ручной опрос выполняется в фоне.';
                                }

                                return sprintf(
                                    'Ручной опрос выполняется в фоне с %s.',
                                    $record->manual_poll_requested_at->format('d.m.Y H:i:s'),
                                );
                            })
                            ->badge()
                            ->color('warning'),
                    ]),
                Section::make('Тонеры')
                    ->poll(fn (Printer $record): ?string => $record->is_polling ? '5s' : null)
                    ->schema([
                        RepeatableEntry::make('displayed_toner_supplies')
                            ->label('')
                            ->state(fn (Printer $record) => $record->displayed_toner_supplies)
                            ->contained(true)
                            ->placeholder('Активные картриджи не найдены')
                            ->schema([
                                Group::make([
                                    TextEntry::make('slot_key')
                                        ->label('Слот')
                                        ->badge()
                                        ->color('gray'),
                                    TextEntry::make('color_label')
                                        ->label('Цвет')
                                        ->badge()
                                        ->state(fn (TonerSupply $record): string => $record->color_label)
                                        ->color(fn (TonerSupply $record): string => $record->color_badge_color),
                                    TextEntry::make('status_label')
                                        ->label('Статус')
                                        ->state(fn (TonerSupply $record): string => $record->status_label)
                                        ->badge()
                                        ->color(fn (TonerSupply $record): string => $record->isLow() ? 'danger' : ($record->percentage === null ? 'warning' : 'success')),
                                    TextEntry::make('identity_pending')
                                        ->label('Подтверждение')
                                        ->state(fn (TonerSupply $record): string => 'Картридж заменён — выберите из истории')
                                        ->badge()
                                        ->color('warning')
                                        ->visible(fn (TonerSupply $record): bool => $record->needs_identity_confirmation),
                                    Actions::make([
                                        self::chooseCartridgeIdentityAction(),
                                    ])
                                        ->visible(fn (TonerSupply $record): bool => $record->needs_identity_confirmation),
                                ])->columnSpan(1),
                                Group::make([
                                    TextEntry::make('display_name')
                                        ->label('Название'),
                                    TextEntry::make('id')
                                        ->label('ID в базе'),
                                    ViewEntry::make('percentage')
                                        ->label('% тонера')
                                        ->view('filament.infolists.entries.toner-progress'),
                                    TextEntry::make('comment_display')
                                        ->label('Комментарий')
                                        ->placeholder('Без комментария')
                                        ->suffixAction(self::editSupplyMetadataAction('edit_active_supply_metadata')),
                                ])->columnSpan(1),
                            ])
                            ->columns(2),
                    ])
                    ->collapsible()
                    ->collapsed(false),
                Section::make('История картриджей')
                    ->poll(fn (Printer $record): ?string => $record->is_polling ? '5s' : null)
                    ->schema([
                        RepeatableEntry::make('tonerHistory')
                            ->label('')
                            ->contained(true)
                            ->placeholder('История картриджей пуста')
                            ->schema([
                                Group::make([
                                    TextEntry::make('id')
                                        ->label('ID'),
                                    TextEntry::make('history_slot_key')
                                        ->label('Слот в принтере')
                                        ->placeholder(fn (TonerSupply $record): string => $record->slot_key ?? '—'),
                                    TextEntry::make('color_label')
                                        ->label('Цвет')
                                        ->badge()
                                        ->state(fn (TonerSupply $record): string => $record->color_label)
                                        ->color(fn (TonerSupply $record): string => $record->color_badge_color),
                                    TextEntry::make('last_seen_at')
                                        ->label('Последний раз в принтере')
                                        ->dateTime()
                                        ->placeholder('Неизвестно'),
                                ])->columnSpan(1),
                                Group::make([
                                    TextEntry::make('display_name')
                                        ->label('Название'),
                                    TextEntry::make('percentage_display')
                                        ->label('% тонера'),
                                    TextEntry::make('comment_display')
                                        ->label('Комментарий')
                                        ->placeholder('Без комментария')
                                        ->suffixAction(self::editSupplyMetadataAction('edit_history_supply_metadata')),
                                    TextEntry::make('removed_at')
                                        ->label('Перемещен в историю')
                                        ->dateTime()
                                        ->placeholder('Неизвестно'),
                                    Actions::make([
                                        self::deleteHistorySupplyAction(),
                                    ]),
                                ])->columnSpan(1),
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

    private static function chooseCartridgeIdentityAction(): Action
    {
        return Action::make('choose_cartridge_identity')
            ->button()
            ->icon('heroicon-m-queue-list')
            ->label('Выбрать')
            ->color('warning')
            ->size('sm')
            ->modalHeading('Выбор картриджа для слота')
            ->modalDescription('Выберите картридж из истории этого слота или сохраните текущий как новый.')
            ->visible(fn (TonerSupply $record): bool => $record->needs_identity_confirmation)
            ->schema([
                Select::make('choice_type')
                    ->label('Действие')
                    ->options([
                        'history' => 'Выбрать из истории',
                        'new' => 'Сохранить как новый',
                    ])
                    ->default('history')
                    ->required()
                    ->live(),
                Select::make('historical_supply_id')
                    ->label('Картридж из истории')
                    ->options(function (TonerSupply $record): array {
                        return app(TonerSupplyIdentityService::class)
                            ->slotHistory($record->printer, (string) $record->slot_key)
                            ->mapWithKeys(fn (TonerSupply $supply): array => [
                                $supply->getKey() => sprintf(
                                    '#%d — %s — %s',
                                    $supply->getKey(),
                                    $supply->display_name,
                                    $supply->comment_display,
                                ),
                            ])
                            ->all();
                    })
                    ->visible(fn (Get $get): bool => $get('choice_type') === 'history')
                    ->required(fn (Get $get): bool => $get('choice_type') === 'history'),
                Textarea::make('comment')
                    ->label('Комментарий для нового картриджа')
                    ->rows(3)
                    ->visible(fn (Get $get): bool => $get('choice_type') === 'new')
                    ->required(fn (Get $get): bool => $get('choice_type') === 'new'),
            ])
            ->action(function (TonerSupply $record, array $data): void {
                $printer = $record->printer;

                if (! $printer instanceof Printer || $record->slot_key === null) {
                    throw new InvalidArgumentException('Не удалось определить принтер или слот.');
                }

                $service = app(TonerSupplyIdentityService::class);

                if (($data['choice_type'] ?? 'history') === 'new') {
                    $service->saveAsNew(
                        $printer,
                        (string) $record->slot_key,
                        (string) ($data['comment'] ?? ''),
                    );
                } else {
                    $historical = TonerSupply::query()->find($data['historical_supply_id'] ?? null);

                    if (! $historical instanceof TonerSupply) {
                        throw new InvalidArgumentException('Картридж из истории не найден.');
                    }

                    $service->selectFromHistory($printer, (string) $record->slot_key, $historical);
                }

                Notification::make()
                    ->title('Картридж для слота подтверждён')
                    ->success()
                    ->send();
            });
    }

    private static function deleteHistorySupplyAction(): Action
    {
        return Action::make('delete_history_supply')
            ->button()
            ->icon('heroicon-m-trash')
            ->label('Удалить')
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Удалить картридж из истории')
            ->modalDescription('Запись будет удалена из базы без возможности восстановления.')
            ->action(function (TonerSupply $record): void {
                app(TonerSupplyIdentityService::class)->deleteFromHistory($record);

                Notification::make()
                    ->title('Картридж удалён из истории')
                    ->success()
                    ->send();
            });
    }

    private static function editSupplyMetadataAction(string $name): Action
    {
        return Action::make($name)
            ->icon('heroicon-m-pencil-square')
            ->label('Изменить')
            ->modalHeading('Параметры картриджа')
            ->schema([
                Select::make('color')
                    ->label('Цвет')
                    ->options(self::colorOptions())
                    ->required(),
                Toggle::make('is_on_service')
                    ->label('Отправлен на обслуживание'),
                Textarea::make('comment')
                    ->label('Комментарий')
                    ->rows(3),
            ])
            ->fillForm(fn (TonerSupply $record): array => [
                'color' => $record->color?->value ?? TonerColor::Unknown->value,
                'comment' => $record->comment,
                'is_on_service' => $record->is_on_service,
            ])
            ->action(function (TonerSupply $record, array $data): void {
                $record->update([
                    'color' => $data['color'],
                    'is_color_manual' => true,
                    'comment' => filled($data['comment'] ?? null) ? trim((string) $data['comment']) : null,
                    'is_on_service' => (bool) ($data['is_on_service'] ?? false),
                ]);

                Notification::make()
                    ->title('Карточка картриджа обновлена')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, string>
     */
    private static function colorOptions(): array
    {
        $options = [];

        foreach (TonerColor::cases() as $color) {
            $options[$color->value] = $color->label();
        }

        return $options;
    }
}
