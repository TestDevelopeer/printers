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
        'awaiting_slot_poll_keys',
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
            'awaiting_slot_poll_keys' => 'array',
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

    /**
     * @return array<int, array{type: string, supply?: TonerSupply, slot_key: string}>
     */
    public function getOrderedTonerDisplayItemsAttribute(): array
    {
        $activeBySlot = $this->displayed_toner_supplies->keyBy('slot_key');
        $awaitingSlots = collect($this->awaiting_slot_poll_keys ?? [])
            ->filter(fn (mixed $slotKey): bool => is_string($slotKey) && $slotKey !== '')
            ->reject(fn (string $slotKey): bool => $activeBySlot->has($slotKey))
            ->values();

        $slotKeys = $activeBySlot->keys()
            ->merge($awaitingSlots)
            ->unique()
            ->sortBy(fn (string $slotKey): array => [
                TonerSupply::slotSortValue($slotKey),
                $slotKey,
            ])
            ->values();

        return $slotKeys
            ->map(function (string $slotKey) use ($activeBySlot): array {
                if ($activeBySlot->has($slotKey)) {
                    return [
                        'type' => 'supply',
                        'slot_key' => $slotKey,
                        'supply' => $activeBySlot->get($slotKey),
                    ];
                }

                return [
                    'type' => 'placeholder',
                    'slot_key' => $slotKey,
                ];
            })
            ->all();
    }

    public function addAwaitingSlotPollKey(string $slotKey): void
    {
        $keys = $this->awaiting_slot_poll_keys ?? [];

        if (! in_array($slotKey, $keys, true)) {
            $keys[] = $slotKey;
            $this->forceFill(['awaiting_slot_poll_keys' => $keys])->save();
        }
    }

    public function removeAwaitingSlotPollKey(string $slotKey): void
    {
        $keys = array_values(array_filter(
            $this->awaiting_slot_poll_keys ?? [],
            static fn (string $key): bool => $key !== $slotKey,
        ));

        $this->forceFill(['awaiting_slot_poll_keys' => $keys])->save();
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
