<?php

namespace App\Filament\Resources\Printers\Schemas;

use App\Filament\Support\ManualPrinterPoll;
use App\Enums\TonerColor;
use App\Models\Printer;
use App\Models\TonerSupply;
use App\Services\Printers\TonerSupplyIdentityService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
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
use Throwable;

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
                                        ->state('Картридж заменён — выберите из истории')
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
                                        ->suffixAction(self::editActiveSupplyMetadataAction()),
                                ])->columnSpan(1),
                            ])
                            ->columns(2),
                        RepeatableEntry::make('awaiting_slot_placeholders')
                            ->label('')
                            ->state(fn (Printer $record): array => $record->awaiting_slot_placeholders)
                            ->contained(true)
                            ->visible(fn (Printer $record): bool => $record->awaiting_slot_placeholders !== [])
                            ->schema([
                                Group::make([
                                    TextEntry::make('slot_key')
                                        ->label('Слот')
                                        ->badge()
                                        ->color('gray'),
                                    TextEntry::make('placeholder_status')
                                        ->label('Статус')
                                        ->state('Ожидает опроса')
                                        ->badge()
                                        ->color('gray'),
                                    Actions::make([
                                        self::refreshPrinterAction(),
                                        self::chooseCartridgeForAwaitingSlotAction(),
                                    ]),
                                ])->columnSpan(1),
                                Group::make([
                                    TextEntry::make('placeholder_hint')
                                        ->label('')
                                        ->state('Картридж отправлен на обслуживание. Выполните опрос принтера, чтобы подтянуть новый картридж.'),
                                ])->columnSpan(1),
                            ])
                            ->columns(2),
                    ])
                    ->collapsible()
                    ->collapsed(false),
                Section::make('История картриджей')
                    ->poll(fn (Printer $record): ?string => $record->is_polling ? '5s' : null)
                    ->schema([
                        RepeatableEntry::make('displayed_toner_history')
                            ->label('')
                            ->state(fn (Printer $record) => $record->displayed_toner_history)
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
                                        ->suffixAction(self::editHistorySupplyMetadataAction()),
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

    private static function chooseCartridgeForAwaitingSlotAction(): Action
    {
        return Action::make('choose_cartridge_for_awaiting_slot')
            ->button()
            ->icon('heroicon-m-queue-list')
            ->label('Выбрать картридж')
            ->color('warning')
            ->size('sm')
            ->modalHeading('Выбор картриджа для слота')
            ->modalDescription('Выберите картридж из истории этого слота и установите его активным.')
            ->fillForm(fn (array $record): array => [
                'slot_key' => (string) ($record['slot_key'] ?? ''),
            ])
            ->schema([
                Hidden::make('slot_key'),
                Select::make('historical_supply_id')
                    ->label('Картридж из истории')
                    ->options(function (Get $get, $livewire): array {
                        $printer = $livewire->getRecord();

                        if (! $printer instanceof Printer) {
                            return [];
                        }

                        $slotKey = (string) $get('slot_key');

                        if ($slotKey === '') {
                            return [];
                        }

                        return app(TonerSupplyIdentityService::class)
                            ->slotHistory($printer, $slotKey)
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
                    ->required(),
            ])
            ->action(function (array $data, $livewire): void {
                $printer = $livewire->getRecord();

                if (! $printer instanceof Printer) {
                    throw new InvalidArgumentException('Не удалось определить принтер.');
                }

                $slotKey = (string) ($data['slot_key'] ?? '');

                if ($slotKey === '') {
                    throw new InvalidArgumentException('Не удалось определить слот.');
                }

                $historical = TonerSupply::query()->find($data['historical_supply_id'] ?? null);

                if (! $historical instanceof TonerSupply) {
                    throw new InvalidArgumentException('Картридж из истории не найден.');
                }

                app(TonerSupplyIdentityService::class)->activateFromHistory(
                    $printer,
                    $slotKey,
                    $historical,
                );

                Notification::make()
                    ->title('Картридж установлен в слот')
                    ->success()
                    ->send();
            });
    }

    private static function refreshPrinterAction(): Action
    {
        return Action::make('refresh_printer_for_slot')
            ->button()
            ->icon('heroicon-m-arrow-path')
            ->label('Обновить принтер')
            ->color('primary')
            ->size('sm')
            ->disabled(function ($livewire): bool {
                $record = $livewire->getRecord();

                return $record instanceof Printer && (bool) $record->is_polling;
            })
            ->action(function ($livewire): void {
                $record = $livewire->getRecord();

                if (! $record instanceof Printer) {
                    throw new InvalidArgumentException('Не удалось определить принтер.');
                }

                try {
                    ManualPrinterPoll::run($record);

                    Notification::make()
                        ->title('Опрос выполнен')
                        ->body("Принтер {$record->display_name} опрошен.")
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Ошибка опроса')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
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

    private static function editActiveSupplyMetadataAction(): Action
    {
        return self::editSupplyMetadataAction('edit_active_supply_metadata');
    }

    private static function editHistorySupplyMetadataAction(): Action
    {
        return self::editSupplyMetadataAction('edit_history_supply_metadata')
            ->visible(fn (TonerSupply $record): bool => $record->removed_at !== null);
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
                TextInput::make('percentage')
                    ->label('% тонера')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(1)
                    ->suffix('%')
                    ->helperText('Ручное значение сохраняется до следующего опроса принтера.'),
                Textarea::make('comment')
                    ->label('Комментарий')
                    ->rows(3),
            ])
            ->fillForm(fn (TonerSupply $record): array => [
                'color' => $record->color?->value ?? TonerColor::Unknown->value,
                'comment' => $record->comment,
                'is_on_service' => $record->is_on_service,
                'percentage' => $record->percentage,
            ])
            ->action(function (TonerSupply $record, array $data): void {
                $willBeOnService = (bool) ($data['is_on_service'] ?? false);
                $manualPercentage = array_key_exists('percentage', $data)
                    ? (is_numeric($data['percentage']) ? (int) $data['percentage'] : null)
                    : $record->percentage;

                if ($record->removed_at === null && $willBeOnService && ! $record->is_on_service) {
                    app(TonerSupplyIdentityService::class)->sendActiveToService(
                        $record,
                        $data['color'],
                        filled($data['comment'] ?? null) ? trim((string) $data['comment']) : null,
                        $manualPercentage,
                    );

                    Notification::make()
                        ->title('Картридж отправлен на обслуживание')
                        ->body('Слот ожидает опроса принтера. Нажмите «Обновить принтер», чтобы подтянуть новый картридж.')
                        ->success()
                        ->send();

                    return;
                }

                if ($record->removed_at !== null && $record->is_on_service && ! $willBeOnService) {
                    $slotKey = (string) ($record->history_slot_key ?? $record->slot_key ?? '');

                    if ($slotKey === '' || ! $record->printer instanceof Printer) {
                        throw new InvalidArgumentException('Не удалось определить принтер или слот.');
                    }

                    app(TonerSupplyIdentityService::class)->activateFromHistory(
                        $record->printer,
                        $slotKey,
                        $record,
                        $data['color'],
                        filled($data['comment'] ?? null) ? trim((string) $data['comment']) : null,
                        $manualPercentage,
                    );

                    Notification::make()
                        ->title('Картридж возвращён в слот')
                        ->body('Текущий активный картридж этого слота перемещён в историю.')
                        ->success()
                        ->send();

                    return;
                }

                $record->update([
                    'color' => $data['color'],
                    'is_color_manual' => true,
                    'comment' => filled($data['comment'] ?? null) ? trim((string) $data['comment']) : null,
                    'is_on_service' => $willBeOnService,
                    'percentage' => $manualPercentage,
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
