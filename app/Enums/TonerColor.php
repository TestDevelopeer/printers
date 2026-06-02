<?php

namespace App\Enums;

enum TonerColor: string
{
    case Black = 'black';
    case Cyan = 'cyan';
    case Magenta = 'magenta';
    case Yellow = 'yellow';
    case Waste = 'waste';
    case Other = 'other';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Black => 'Black',
            self::Cyan => 'Cyan',
            self::Magenta => 'Magenta',
            self::Yellow => 'Yellow',
            self::Waste => 'Waste',
            self::Other => 'Other',
            self::Unknown => 'Unknown',
        };
    }
}
