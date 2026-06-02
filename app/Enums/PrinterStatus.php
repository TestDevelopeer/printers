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
            self::Online => 'В сети',
            self::Offline => 'Не в сети',
            self::Error => 'Ошибка',
            self::Unknown => 'Неизвестно',
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
