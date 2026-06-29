<?php

namespace App\Filament\Resources\Printers\Schemas;

use App\Filament\Support\ManualPrinterPoll;
use App\Enums\TonerColor;
use App\Models\Printer;
use App\Models\PrinterPollLog;
use App\Models\TonerSupply;
use App\Services\Printers\MeterReadingService;
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

class PrinterInfolist {
    public static function configure(Schema $schema): Schema {
        return $schema
            ->components([
                Section::make('Фоновый опрос')
                    ->poll(fn(Printer $record): ?string => $record->is_polling ? '5s' : null)
                    ->visible(fn(Printer $record): bool => (bool) $record->is_polling)
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
                Section::make('Картриджи')
                    ->poll(fn(Printer $record): ?string => $record->is_polling ? '5s' : null)
                    ->schema([
                        RepeatableEntry::make('displayed_toner_supplies')
                            ->label('')
                            ->state(fn(Printer $record) => $record->displayed_toner_supplies)
                            ->contained(true)
                            ->placeholder('Активные картриджи не найдены. Свободные картриджи доступны на странице «Картриджи».')
                            ->schema([
                                Group::make([
                                    TextEntry::make('slot_key')
                                        ->label('Слот')
                                        ->badge()
                                        ->color('gray'),
                                    TextEntry::make('color_label')
                                        ->label('Цвет')
                                        ->badge()
                                        ->state(fn(TonerSupply $record): string => $record->color_label)
                                        ->color(fn(TonerSupply $record): string => $record->color_badge_color),
                                    TextEntry::make('status_label')
                                        ->label('Статус')
                                        ->state(fn(TonerSupply $record): string => $record->status_label)
                                        ->badge()
                                        ->color(fn(TonerSupply $record): string => $record->isLow() ? 'danger' : ($record->percentage === null ? 'warning' : 'success')),
                                    TextEntry::make('identity_pending')
                                        ->label('Подтверждение')
                                        ->state('Картридж заменён — выберите из пула')
                                        ->badge()
                                        ->color('warning')
                                        ->visible(fn(TonerSupply $record): bool => $record->needs_identity_confirmation),
                                    Actions::make([
                                        self::chooseCartridgeIdentityAction(),
                                    ])
                                        ->visible(fn(TonerSupply $record): bool => $record->needs_identity_confirmation),
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
                            ->label('Ожидают опроса')
                            ->state(fn(Printer $record) => $record->awaiting_slot_placeholders)
                            ->contained(true)
                            ->placeholder(null)
                            ->schema([
                                Group::make([
                                    TextEntry::make('slot_key')
                                        ->label('Слот')
                                        ->badge()
                                        ->color('gray'),
                                    TextEntry::make('hint')
                                        ->label('Состояние')
                                        ->state('Слот пуст, ожидается новый картридж')
                                        ->badge()
                                        ->color('warning'),
                                ])->columnSpan(1),
                                Group::make([
                                    TextEntry::make('refresh')
                                        ->label('Опрос')
                                        ->state('Создать картридж из SNMP-данных принтера')
                                        ->suffixAction(self::refreshPrinterForSlotAction()),
                                    TextEntry::make('choose')
                                        ->label('Установка')
                                        ->state('Выбрать картридж из пула на обслуживании')
                                        ->suffixAction(self::chooseCartridgeForAwaitingSlotAction()),
                                ])->columnSpan(1),
                            ])
                            ->columns(2),
                    ])
                    ->collapsible()
                    ->collapsed(false),
                Section::make('Счетчики')
                    ->poll(fn(Printer $record): ?string => $record->is_polling ? '5s' : null)
                    ->schema([
                        TextEntry::make('total_pages_display')
                            ->label('Всего страниц (lifetime)')
                            ->state(fn(Printer $record): ?string => $record->latestMeterReading?->total_pages !== null
                                ? number_format($record->latestMeterReading->total_pages, 0, '.', ' ')
                                : null)
                            ->placeholder('Нет данных')
                            ->helperText('Суммарное значение SNMP prtMarkerLifeCount на момент последнего опроса.'),
                        ViewEntry::make('meter_breakdown')
                            ->label('Разбивка за последние 7 дней')
                            ->view('filament.resources.printers.meter-breakdown')
                            ->viewData(fn(Printer $record): array => [
                                'breakdown' => app(MeterReadingService::class)->getDailyBreakdown($record, 7),
                            ]),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(false),
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
                            ->formatStateUsing(fn($state) => $state?->label() ?? 'Неизвестно')
                            ->color(fn($state) => $state?->color() ?? 'warning'),
                        TextEntry::make('last_error')->label('Последняя ошибка')->placeholder('Ошибок нет'),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(false),
                Section::make('Сеть')
                    ->schema([
                        TextEntry::make('ip_address')->label('IP-адрес'),
                        TextEntry::make('mac_address')->label('MAC-адрес')->placeholder('Неизвестно'),
                        TextEntry::make('hostname')->label('Hostname')->placeholder('Неизвестно'),
                        TextEntry::make('snmp_community')->label('SNMP community'),
                        TextEntry::make('snmp_version')->label('SNMP version'),
                        TextEntry::make('last_polled_at')
                            ->label('Последний опрос')
                            ->dateTime()
                            ->placeholder('Не опрашивался'),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(),
                Section::make('Прочее')
                    ->schema([
                        TextEntry::make('is_active')
                            ->label('Активен')
                            ->formatStateUsing(fn($state) => $state ? 'Да' : 'Нет')
                            ->badge()
                            ->color(fn($state) => $state ? 'success' : 'gray'),
                        TextEntry::make('created_at')
                            ->label('Создан')
                            ->dateTime()
                            ->placeholder('Неизвестно'),
                        TextEntry::make('updated_at')
                            ->label('Обновлён')
                            ->dateTime()
                            ->placeholder('Неизвестно'),
                    ])
                    ->columns(3)
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    private static function chooseCartridgeIdentityAction(): Action {
        return Action::make('choose_cartridge_identity')
            ->button()
            ->icon('heroicon-m-queue-list')
            ->label('Выбрать')
            ->color('warning')
            ->size('sm')
            ->modalHeading('Выбор картриджа для слота')
            ->modalDescription('Выберите картридж из пула на обслуживании или сохраните текущий как новый.')
            ->visible(fn(TonerSupply $record): bool => $record->needs_identity_confirmation)
            ->schema([
                Select::make('choice_type')
                    ->label('Действие')
                    ->options([
                        'service' => 'Выбрать из пула на обслуживании',
                        'new' => 'Сохранить как новый',
                    ])
                    ->default('service')
                    ->required()
                    ->live(),
                Select::make('service_supply_id')
                    ->label('Картридж из пула')
                    ->helperText('Начните вводить номер, описание или комментарий для поиска')
                    ->searchable()
                    ->getSearchResultsUsing(fn(string $search): array => TonerSupply::query()
                        ->onService()
                        ->where(function ($query) use ($search): void {
                            $query->where('snmp_description', 'like', "%{$search}%")
                                ->orWhere('comment', 'like', "%{$search}%")
                                ->orWhere('id', 'like', "%{$search}%");
                        })
                        ->orderBy('color')
                        ->orderBy('snmp_description')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn(TonerSupply $supply): array => [
                            $supply->getKey() => sprintf(
                                '#%d — %s — %s',
                                $supply->getKey(),
                                $supply->display_name,
                                $supply->comment_display,
                            ),
                        ])
                        ->all())
                    ->getOptionLabelUsing(fn(mixed $value): ?string => TonerSupply::query()
                        ->onService()
                        ->whereKey($value)
                        ->first()
                        ?->display_name)
                    ->visible(fn(Get $get): bool => $get('choice_type') === 'service')
                    ->required(fn(Get $get): bool => $get('choice_type') === 'service'),
                Textarea::make('comment')
                    ->label('Комментарий для нового картриджа')
                    ->rows(3)
                    ->visible(fn(Get $get): bool => $get('choice_type') === 'new')
                    ->required(fn(Get $get): bool => $get('choice_type') === 'new'),
            ])
            ->action(function (TonerSupply $record, array $data): void {
                $printer = $record->printer;

                if (! $printer instanceof Printer || $record->slot_key === null) {
                    throw new InvalidArgumentException('Не удалось определить принтер или слот.');
                }

                $service = app(TonerSupplyIdentityService::class);

                if (($data['choice_type'] ?? 'service') === 'new') {
                    $service->confirmProvisionalAsNew(
                        $record,
                        (string) ($data['comment'] ?? ''),
                    );
                } else {
                    $serviceSupply = TonerSupply::query()->find($data['service_supply_id'] ?? null);

                    if (! $serviceSupply instanceof TonerSupply) {
                        throw new InvalidArgumentException('Картридж из пула не найден.');
                    }

                    $service->installFromService($printer, (string) $record->slot_key, $serviceSupply, $record);
                }

                Notification::make()
                    ->title('Картридж для слота подтверждён')
                    ->success()
                    ->send();
            });
    }

    private static function refreshPrinterForSlotAction(): Action
    {
        return Action::make('refresh_printer_for_slot')
            ->button()
            ->icon('heroicon-m-arrow-path')
            ->label('Обновить принтер')
            ->color('primary')
            ->size('sm')
            ->modalHeading('Обновить принтер')
            ->modalDescription('Будет выполнен опрос принтера. Если в этом слоте есть данные SNMP, будет создан новый картридж для подтверждения.')
            ->requiresConfirmation()
            ->modalSubmitActionLabel('Опросить')
            ->action(function ($livewire): void {
                $printer = $livewire->getRecord();

                if (! $printer instanceof Printer) {
                    throw new InvalidArgumentException('Не удалось определить принтер.');
                }

                $slotKey = self::resolveAwaitingSlotKeyFromLivewire($livewire);

                if ($slotKey === '') {
                    throw new InvalidArgumentException('Не удалось определить слот.');
                }

                $printer->addAwaitingSlotPollKey($slotKey);

                try {
                    ManualPrinterPoll::run($printer, createProvisionalForEmptySlots: true);

                    Notification::make()
                        ->title('Опрос поставлен в очередь')
                        ->body("Принтер {$printer->display_name} поставлен в очередь на опрос. Результат будет в логах.")
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

    private static function chooseCartridgeForAwaitingSlotAction(): Action {
        return Action::make('choose_cartridge_for_awaiting_slot')
            ->button()
            ->icon('heroicon-m-queue-list')
            ->label('Выбрать картридж')
            ->color('warning')
            ->size('sm')
            ->modalHeading('Выбор картриджа для слота')
            ->modalDescription('Выберите картридж из пула на обслуживании. Текущий активный картридж слота будет отправлен на обслуживание.')
            ->fillForm(function (array $arguments, mixed $record): array {
                return [
                    'slot_key' => self::resolveAwaitingSlotKey($arguments, $record),
                ];
            })
            ->schema([
                Hidden::make('slot_key'),
                Select::make('service_supply_id')
                    ->label('Картридж из пула')
                    ->helperText('Начните вводить номер, описание или комментарий для поиска')
                    ->searchable()
                    ->getSearchResultsUsing(fn(string $search): array => TonerSupply::query()
                        ->onService()
                        ->where(function ($query) use ($search): void {
                            $query->where('snmp_description', 'like', "%{$search}%")
                                ->orWhere('comment', 'like', "%{$search}%")
                                ->orWhere('id', 'like', "%{$search}%");
                        })
                        ->orderBy('color')
                        ->orderBy('snmp_description')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn(TonerSupply $supply): array => [
                            $supply->getKey() => sprintf(
                                '#%d — %s — %s',
                                $supply->getKey(),
                                $supply->display_name,
                                $supply->comment_display,
                            ),
                        ])
                        ->all())
                    ->getOptionLabelUsing(fn(mixed $value): ?string => TonerSupply::query()
                        ->onService()
                        ->whereKey($value)
                        ->first()
                        ?->display_name)
                    ->required(),
            ])
            ->action(function (mixed $form, $livewire): void {
                $printer = $livewire->getRecord();

                if (! $printer instanceof Printer) {
                    throw new InvalidArgumentException('Не удалось определить принтер.');
                }

                $state = $form instanceof \Filament\Forms\Form ? $form->getState() : (is_array($form) ? $form : []);
                $slotKey = self::resolveAwaitingSlotKeyFromLivewire($livewire, $state);

                if ($slotKey === '') {
                    throw new InvalidArgumentException('Не удалось определить слот.');
                }

                $serviceSupply = TonerSupply::query()->find($state['service_supply_id'] ?? null);

                if (! $serviceSupply instanceof TonerSupply) {
                    throw new InvalidArgumentException('Картридж из пула не найден.');
                }

                try {
                    app(TonerSupplyIdentityService::class)->installFromService(
                        $printer,
                        $slotKey,
                        $serviceSupply,
                    );
                } catch (Throwable $exception) {
                    if (function_exists('logger')) {
                        logger()->error('installFromService failed in chooseCartridge action: ' . $exception->getMessage(), [
                            'exception' => $exception,
                            'printer_id' => $printer->getKey(),
                            'slot_key' => $slotKey,
                            'service_supply_id' => $serviceSupply->getKey(),
                        ]);
                    }
                    Notification::make()
                        ->title('Не удалось установить картридж')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Картридж установлен в слот')
                    ->success()
                    ->send();
            });
    }

    private static function refreshPrinterAction(): Action {
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

    private static function editActiveSupplyMetadataAction(): Action {
        return Action::make('edit_active_supply_metadata')
            ->icon('heroicon-m-pencil-square')
            ->label('Изменить')
            ->modalHeading('Параметры картриджа')
            ->schema([
                Select::make('color')
                    ->label('Цвет')
                    ->options(self::colorOptions())
                    ->required(),
                Toggle::make('is_on_service')
                    ->label('Отправить на обслуживание')
                    ->helperText('Картридж будет отвязан от принтера и появится в общем пуле.'),
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
            ->fillForm(fn(TonerSupply $record): array => [
                'color' => $record->color?->value ?? TonerColor::Unknown->value,
                'comment' => $record->comment,
                'is_on_service' => false,
                'percentage' => $record->percentage,
            ])
            ->action(function (TonerSupply $record, array $data): void {
                $willBeOnService = (bool) ($data['is_on_service'] ?? false);
                $manualPercentage = array_key_exists('percentage', $data)
                    ? (is_numeric($data['percentage']) ? (int) $data['percentage'] : null)
                    : $record->percentage;

                if ($record->removed_at === null && $willBeOnService) {
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

                $record->update([
                    'color' => $data['color'],
                    'is_color_manual' => true,
                    'comment' => filled($data['comment'] ?? null) ? trim((string) $data['comment']) : null,
                    'percentage' => $manualPercentage,
                ]);

                Notification::make()
                    ->title('Карточка картриджа обновлена')
                    ->success()
                    ->send();
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $formState
     */
    private static function resolveAwaitingSlotKey(array $arguments, mixed $record = null, array $formState = []): string
    {
        $slotKey = (string) ($formState['slot_key'] ?? $arguments['slot_key'] ?? '');

        if ($slotKey !== '') {
            return $slotKey;
        }

        if (is_array($record)) {
            $slotKey = (string) ($record['slot_key'] ?? '');

            if ($slotKey !== '') {
                return $slotKey;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $formState
     */
    private static function resolveAwaitingSlotKeyFromLivewire(mixed $livewire, array $formState = []): string
    {
        $mountedActions = $livewire->mountedActions ?? [];
        $lastAction = end($mountedActions) ?: [];
        $arguments = is_array($lastAction['arguments'] ?? null) ? $lastAction['arguments'] : [];

        return self::resolveAwaitingSlotKey($arguments, null, $formState);
    }

    /**
     * @return array<string, string>
     */
    private static function colorOptions(): array {
        $options = [];

        foreach (TonerColor::cases() as $color) {
            $options[$color->value] = $color->label();
        }

        return $options;
    }
}
