<?php

namespace App\Filament\Resources\Printers\Schemas;

use App\Enums\PrinterStatus;
use App\Filament\Resources\Printers\PrinterResource;
use Filament\Forms\Components\Repeater;
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
                Section::make('Printer')
                    ->schema([
                        TextInput::make('name')
                            ->label('Display name')
                            ->maxLength(255),
                        TextInput::make('discovered_name')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('ip_address')
                            ->required()
                            ->label('IP address')
                            ->ipv4(),
                        TextInput::make('hostname'),
                        TextInput::make('mac_address'),
                        TextInput::make('manufacturer'),
                        TextInput::make('model'),
                        TextInput::make('serial_number'),
                        TextInput::make('location'),
                        Select::make('status')
                            ->options([
                                PrinterStatus::Online->value => PrinterStatus::Online->label(),
                                PrinterStatus::Offline->value => PrinterStatus::Offline->label(),
                                PrinterStatus::Error->value => PrinterStatus::Error->label(),
                                PrinterStatus::Unknown->value => PrinterStatus::Unknown->label(),
                            ])
                            ->default(PrinterStatus::Unknown->value)
                            ->required(),
                        Toggle::make('is_active')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('SNMP')
                    ->schema([
                        TextInput::make('snmp_community')
                            ->required()
                            ->default(config('printers.default_snmp_community', 'public')),
                        Select::make('snmp_version')
                            ->required()
                            ->options([
                                '2c' => '2c',
                            ])
                            ->default(config('printers.default_snmp_version', '2c')),
                        Textarea::make('last_error')
                            ->rows(3)
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Cartridge sets')
                    ->description('Manage compatible cartridge sets directly on the printer form.')
                    ->schema([
                        Repeater::make('cartridgeSets')
                            ->relationship()
                            ->defaultItems(0)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Textarea::make('description')
                                    ->rows(2)
                                    ->columnSpanFull(),
                                Repeater::make('cartridges')
                                    ->relationship()
                                    ->defaultItems(0)
                                    ->schema([
                                        TextInput::make('name')
                                            ->required()
                                            ->maxLength(255),
                                        Select::make('color')
                                            ->required()
                                            ->default('other')
                                            ->options(PrinterResource::tonerColorOptions()),
                                        TextInput::make('part_number')
                                            ->label('Part number'),
                                        Textarea::make('notes')
                                            ->rows(2)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(3)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
