<?php

namespace App\Models;

use App\Enums\PrinterStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Printer extends Model
{
    /** @use HasFactory<\Database\Factories\PrinterFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'discovered_name',
        'ip_address',
        'mac_address',
        'hostname',
        'manufacturer',
        'model',
        'serial_number',
        'location',
        'snmp_community',
        'snmp_version',
        'status',
        'last_seen_at',
        'last_polled_at',
        'manual_poll_requested_at',
        'last_error',
        'is_active',
        'is_polling',
    ];

    protected function casts(): array
    {
        return [
            'status' => PrinterStatus::class,
            'last_seen_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'manual_poll_requested_at' => 'datetime',
            'is_active' => 'boolean',
            'is_polling' => 'boolean',
        ];
    }

    public function tonerSupplies(): HasMany
    {
        return $this->hasMany(TonerSupply::class)
            ->whereNull('removed_at')
            ->orderByInstallationSlot();
    }

    public function tonerHistory(): HasMany
    {
        return $this->hasMany(TonerSupply::class)
            ->whereNotNull('removed_at')
            ->orderByInstallationSlot(preferHistorySlot: true)
            ->orderByDesc('removed_at');
    }

    public function allTonerSupplies(): HasMany
    {
        return $this->hasMany(TonerSupply::class)
            ->orderBy('color')
            ->orderBy('snmp_description');
    }

    public function incomingPendingTonerSupplies(): HasMany
    {
        return $this->hasMany(TonerSupply::class, 'transfer_target_printer_id')
            ->whereColumn('toner_supplies.printer_id', '!=', 'toner_supplies.transfer_target_printer_id')
            ->orderBy('color')
            ->orderBy('snmp_description');
    }

    public function cartridgeSets(): HasMany
    {
        return $this->hasMany(CartridgeSet::class);
    }

    public function pollLogs(): HasMany
    {
        return $this->hasMany(PrinterPollLog::class)->latest('started_at');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name ?: ($this->discovered_name ?: $this->ip_address);
    }

    public function getDisplayedTonerSuppliesAttribute(): EloquentCollection
    {
        $supplies = $this->relationLoaded('tonerSupplies')
            ? $this->tonerSupplies
            : $this->tonerSupplies()->get();

        return TonerSupply::sortByInstallationSlot($supplies);
    }

    public function getDisplayedTonerHistoryAttribute(): EloquentCollection
    {
        $supplies = $this->relationLoaded('tonerHistory')
            ? $this->tonerHistory
            : $this->tonerHistory()->get();

        return TonerSupply::sortByInstallationSlot($supplies, preferHistorySlot: true);
    }

    public function getTonerSummaryAttribute(): string
    {
        $supplies = $this->tonerSupplies;

        if ($supplies->isEmpty()) {
            return 'Нет данных';
        }

        $known = $supplies->filter(fn (TonerSupply $supply) => $supply->percentage !== null);
        $lowCount = $supplies->filter(fn (TonerSupply $supply) => $supply->isLow())->count();

        if ($known->isEmpty()) {
            return 'Неизвестно';
        }

        $average = (int) round($known->avg('percentage'));

        return $lowCount > 0
            ? sprintf('Средний уровень %d%%, низкий: %d', $average, $lowCount)
            : sprintf('Средний уровень %d%%', $average);
    }

    public function getHasLowTonerAttribute(): bool
    {
        return $this->tonerSupplies->contains(fn (TonerSupply $supply) => $supply->isLow());
    }
}
