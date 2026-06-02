<?php

namespace App\Filament\Resources\Printers\Schemas;

use App\Enums\PrinterStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;

class PrinterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Принтер')
                    ->schema([
                        TextInput::make('name')
                            ->label('Отображаемое имя')
                            ->maxLength(255),
                        TextInput::make('discovered_name')
                            ->label('Обнаруженное имя')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('ip_address')
                            ->required()
                            ->label('IP-адрес')
                            ->ipv4(),
                        TextInput::make('hostname')
                            ->label('Хост'),
                        TextInput::make('mac_address')
                            ->label('MAC-адрес'),
                        TextInput::make('manufacturer')
                            ->label('Производитель'),
                        TextInput::make('model')
                            ->label('Модель'),
                        TextInput::make('serial_number')
                            ->label('Серийный номер'),
                        TextInput::make('location')
                            ->label('Расположение'),
                        Select::make('status')
                            ->label('Статус')
                            ->options([
                                PrinterStatus::Online->value => PrinterStatus::Online->label(),
                                PrinterStatus::Offline->value => PrinterStatus::Offline->label(),
                                PrinterStatus::Error->value => PrinterStatus::Error->label(),
                                PrinterStatus::Unknown->value => PrinterStatus::Unknown->label(),
                            ])
                            ->default(PrinterStatus::Unknown->value)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Активен')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('SNMP')
                    ->schema([
                        TextInput::make('snmp_community')
                            ->label('Community')
                            ->required()
                            ->default(config('printers.default_snmp_community', 'public')),
                        Select::make('snmp_version')
                            ->label('Версия')
                            ->required()
                            ->options([
                                '2c' => '2c',
                            ])
                            ->default(config('printers.default_snmp_version', '2c')),
                        Textarea::make('last_error')
                            ->label('Последняя ошибка')
                            ->rows(3)
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
