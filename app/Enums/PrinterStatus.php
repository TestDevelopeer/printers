<?php

namespace App\Enums;

enum PrinterStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Error = 'error';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Offline => 'Offline',
            self::Error => 'Error',
            self::Unknown => 'Unknown',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Online => 'success',
            self::Offline => 'gray',
            self::Error => 'danger',
            self::Unknown => 'warning',
        };
    }
}
