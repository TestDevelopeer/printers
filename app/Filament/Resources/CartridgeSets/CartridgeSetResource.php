<?php

namespace App\Filament\Resources\CartridgeSets;

use App\Filament\Resources\CartridgeSets\Pages\CreateCartridgeSet;
use App\Filament\Resources\CartridgeSets\Pages\EditCartridgeSet;
use App\Filament\Resources\CartridgeSets\Pages\ListCartridgeSets;
use App\Filament\Resources\CartridgeSets\Schemas\CartridgeSetForm;
use App\Filament\Resources\CartridgeSets\Tables\CartridgeSetsTable;
use App\Models\CartridgeSet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CartridgeSetResource extends Resource
{
    protected static ?string $model = CartridgeSet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return CartridgeSetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CartridgeSetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCartridgeSets::route('/'),
            'create' => CreateCartridgeSet::route('/create'),
            'edit' => EditCartridgeSet::route('/{record}/edit'),
        ];
    }
}
