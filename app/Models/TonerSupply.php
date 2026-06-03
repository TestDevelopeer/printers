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

    public function needsTransferConfirmation(): bool
    {
        return $this->transfer_target_printer_id !== null
            && $this->transfer_target_printer_id !== $this->printer_id;
    }

    public function isPendingForPrinter(?int $printerId): bool
    {
        return $printerId !== null
            && $this->transfer_target_printer_id === $printerId
            && $this->needsTransferConfirmation();
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

    public function getTransferWarningAttribute(): ?string
    {
        if (! $this->needsTransferConfirmation()) {
            return null;
        }

        $printer = $this->relationLoaded('printer') ? $this->printer : $this->printer()->first();
        $printerName = $printer?->display_name ?? 'другому принтеру';
        $printerIp = $printer?->ip_address ? " ({$printer->ip_address})" : '';

        return "Ранее принадлежал принтеру {$printerName}{$printerIp}";
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
