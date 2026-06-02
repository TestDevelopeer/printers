<?php

namespace App\Models;

use App\Enums\TonerColor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TonerSupply extends Model
{
    /** @use HasFactory<\Database\Factories\TonerSupplyFactory> */
    use HasFactory;

    protected $fillable = [
        'printer_id',
        'slot_key',
        'supply_signature',
        'color',
        'snmp_description',
        'level',
        'max_capacity',
        'percentage',
        'unit',
        'is_known',
        'raw_value',
        'installed_at',
        'removed_at',
        'last_seen_at',
        'comment',
        'is_on_service',
    ];

    protected function casts(): array
    {
        return [
            'color' => TonerColor::class,
            'is_known' => 'boolean',
            'raw_value' => 'array',
            'installed_at' => 'datetime',
            'removed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_on_service' => 'boolean',
        ];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function isLow(): bool
    {
        return $this->percentage !== null
            && $this->percentage <= config('printers.low_toner_threshold', 15);
    }

    public function getStatusLabelAttribute(): string
    {
        if (! $this->is_known || $this->percentage === null) {
            return 'Неизвестно';
        }

        return $this->isLow() ? 'Низкий' : 'Норма';
    }

    public function getColorLabelAttribute(): string
    {
        return ($this->color ?? TonerColor::Unknown)->label();
    }

    public function getPercentageDisplayAttribute(): string
    {
        return $this->percentage === null ? 'Неизвестно' : "{$this->percentage}%";
    }

    public function getIsInstalledAttribute(): bool
    {
        return $this->removed_at === null;
    }

    public function getCommentDisplayAttribute(): string
    {
        return filled($this->comment) ? $this->comment : 'Без комментария';
    }

    public function getServiceStatusLabelAttribute(): string
    {
        return $this->is_on_service ? 'На обслуживании' : 'В работе';
    }

    public function getIdentityKeyAttribute(): string
    {
        return self::buildSupplySignature(
            $this->color?->value ?? 'unknown',
            $this->snmp_description,
        );
    }

    public static function buildSupplySignature(?string $color, ?string $description): string
    {
        $color = $color ?: 'unknown';
        $description = mb_strtolower(trim((string) $description));
        $description = preg_replace('/\s+/u', ' ', $description) ?? '';

        return $description === ''
            ? $color
            : "{$color}:{$description}";
    }
}
