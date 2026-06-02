<?php

namespace App\Filament\Resources\Printers\Schemas;

use App\Models\Cartridge;
use App\Models\CartridgeSet;
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
                Section::make('General')
                    ->schema([
                        TextEntry::make('display_name')->label('Name'),
                        TextEntry::make('discovered_name')->placeholder('Not discovered'),
                        TextEntry::make('manufacturer')->placeholder('Unknown'),
                        TextEntry::make('model')->placeholder('Unknown'),
                        TextEntry::make('serial_number')->placeholder('Unknown'),
                        TextEntry::make('location')->placeholder('Unknown'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state?->label() ?? 'Unknown')
                            ->color(fn ($state) => $state?->color() ?? 'warning'),
                        TextEntry::make('last_error')->placeholder('No errors'),
                    ])
                    ->columns(2),
                Section::make('Network')
                    ->schema([
                        TextEntry::make('ip_address')->label('IP'),
                        TextEntry::make('mac_address')->placeholder('Unknown'),
                        TextEntry::make('hostname')->placeholder('Unknown'),
                        TextEntry::make('snmp_version'),
                        TextEntry::make('snmp_community'),
                        TextEntry::make('last_seen_at')->dateTime()->placeholder('Never'),
                        TextEntry::make('last_polled_at')->dateTime()->placeholder('Never'),
                        IconEntry::make('is_active')->boolean(),
                    ])
                    ->columns(2),
                Section::make('Toner status')
                    ->schema([
                        RepeatableEntry::make('tonerSupplies')
                            ->label('')
                            ->schema([
                                TextEntry::make('color_label')
                                    ->label('Color')
                                    ->state(fn (TonerSupply $record): string => $record->color_label),
                                TextEntry::make('snmp_description')
                                    ->label('Description')
                                    ->placeholder('Unknown'),
                                ViewEntry::make('percentage')
                                    ->label('Level')
                                    ->view('filament.infolists.entries.toner-progress'),
                                TextEntry::make('level')->placeholder('Unknown'),
                                TextEntry::make('max_capacity')->label('Capacity')->placeholder('Unknown'),
                                TextEntry::make('status_label')
                                    ->label('Status')
                                    ->state(fn (TonerSupply $record): string => $record->status_label)
                                    ->badge()
                                    ->color(fn (TonerSupply $record): string => $record->isLow() ? 'danger' : ($record->percentage === null ? 'warning' : 'success')),
                            ])
                            ->columns(2),
                    ]),
                Section::make('Cartridge sets')
                    ->schema([
                        RepeatableEntry::make('cartridgeSets')
                            ->label('')
                            ->schema([
                                TextEntry::make('name')
                                    ->state(fn (CartridgeSet $record): string => $record->name),
                                TextEntry::make('description')
                                    ->placeholder('No description'),
                                RepeatableEntry::make('cartridges')
                                    ->label('Cartridges')
                                    ->schema([
                                        TextEntry::make('name')
                                            ->state(fn (Cartridge $record): string => $record->name),
                                        TextEntry::make('color_label')
                                            ->state(fn (Cartridge $record): string => $record->color_label),
                                        TextEntry::make('part_number')->label('Part number')->placeholder('Not set'),
                                        TextEntry::make('notes')->placeholder('No notes'),
                                    ])
                                    ->columns(2),
                            ])
                            ->columns(1),
                    ]),
            ]);
    }
}
