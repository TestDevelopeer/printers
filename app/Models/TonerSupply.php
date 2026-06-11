<?php

namespace App\Models;

use App\Enums\TonerColor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

    public function scopeInHistory(Builder $query): Builder
    {
        return $query->whereNotNull('removed_at');
    }

    public function scopeOrderByInstallationSlot(Builder $query, bool $preferHistorySlot = false): Builder
    {
        $slotExpression = $preferHistorySlot ? 'COALESCE(history_slot_key, slot_key)' : 'slot_key';
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            return $query
                ->orderByRaw(
                    "CASE WHEN {$slotExpression} ~ '^[0-9]+$' THEN {$slotExpression}::integer ELSE 2147483647 END ASC"
                )
                ->orderByRaw("{$slotExpression} ASC");
        }

        return $query
            ->orderByRaw("CAST({$slotExpression} AS INTEGER) ASC")
            ->orderByRaw("{$slotExpression} ASC");
    }

    /**
     * @param  EloquentCollection<int, TonerSupply>  $supplies
     * @return EloquentCollection<int, TonerSupply>
     */
    public static function sortByInstallationSlot(
        EloquentCollection $supplies,
        bool $preferHistorySlot = false,
    ): EloquentCollection {
        return $supplies
            ->sortBy(fn (TonerSupply $supply): array => [
                self::slotSortValue($preferHistorySlot
                    ? ($supply->history_slot_key ?? $supply->slot_key)
                    : $supply->slot_key),
                $preferHistorySlot
                    ? ($supply->history_slot_key ?? $supply->slot_key ?? '')
                    : ($supply->slot_key ?? ''),
            ])
            ->values();
    }

    public static function slotSortValue(?string $slotKey): int
    {
        if ($slotKey !== null && ctype_digit($slotKey)) {
            return (int) $slotKey;
        }

        return PHP_INT_MAX;
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

    public function getDisplaySlotAttribute(): string
    {
        return $this->history_slot_key ?? $this->slot_key ?? '—';
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
