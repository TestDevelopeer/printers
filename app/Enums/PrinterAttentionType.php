<?php

namespace App\Enums;

enum PrinterAttentionType: string
{
    case LowToner = 'low_toner';
    case IdentityConfirmation = 'identity_confirmation';
    case EmptySlot = 'empty_slot';

    public function label(): string
    {
        return match ($this) {
            self::LowToner => 'Низкий тонер',
            self::IdentityConfirmation => 'Выбрать картридж',
            self::EmptySlot => 'Нет картриджа в слоте',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::LowToner => 'danger',
            self::IdentityConfirmation => 'warning',
            self::EmptySlot => 'gray',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::LowToner => 1,
            self::IdentityConfirmation => 2,
            self::EmptySlot => 3,
        };
    }
}
