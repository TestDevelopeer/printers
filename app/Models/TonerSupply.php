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
        'history_slot_key',
        'supply_signature',
        'color',
        'detected_color',
        'is_color_manual',
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
        'needs_identity_confirmation',
        'replacement_detected_at',
        'transfer_target_printer_id',
        'transfer_detected_at',
    ];

    protected function casts(): array
    {
        return [
            'color' => TonerColor::class,
            'detected_color' => TonerColor::class,
            'is_known' => 'boolean',
            'raw_value' => 'array',
            'installed_at' => 'datetime',
            'removed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_on_service' => 'boolean',
            'is_color_manual' => 'boolean',
            'needs_identity_confirmation' => 'boolean',
            'replacement_detected_at' => 'datetime',
            'transfer_detected_at' => 'datetime',
        ];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function transferTargetPrinter(): BelongsTo
    {
        return $this->belongsTo(Printer::class, 'transfer_target_printer_id');
    }

    public function isLow(): bool
    {
        return $this->percentage !== null
            && $this->percentage <= config('printers.low_toner_threshold', 15);
    }

    public function needsIdentityConfirmation(): bool
    {
        return (bool) $this->needs_identity_confirmation;
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

    public function getColorBadgeColorAttribute(): string
    {
        return ($this->color ?? TonerColor::Unknown)->badgeColor();
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

    public function getDisplayNameAttribute(): string
    {
        if (filled($this->snmp_description)) {
            return $this->snmp_description;
        }

        return 'Картридж #'.$this->getKey();
    }

    public function getIdentityKeyAttribute(): string
    {
        return sprintf(
            '%d:%s:%d',
            $this->printer_id ?? 0,
            $this->slot_key ?? 'unknown',
            $this->getKey() ?? 0,
        );
    }
}
