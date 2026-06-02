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
            self::Black => 'Черный',
            self::Cyan => 'Голубой',
            self::Magenta => 'Пурпурный',
            self::Yellow => 'Желтый',
            self::Waste => 'Отработка',
            self::Other => 'Другой',
            self::Unknown => 'Неизвестно',
        };
    }
}
