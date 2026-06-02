<?php

namespace App\Services\Printers;

use App\Enums\PrinterStatus;
use App\Models\Printer;
use App\Models\TonerSupply;
use App\Services\Printers\Data\DiscoveredPrinterData;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PrinterPollingService
{
    public function __construct(
        private readonly PrinterSnmpService $printerSnmpService,
    ) {
    }

    public function poll(Printer $printer): Printer
    {
        try {
            $discovered = $this->printerSnmpService->discover(
                $printer->ip_address,
                $printer->snmp_community,
                config('printers.poll_timeout', 1000),
            );

            if ($discovered === null) {
                return $this->markOffline($printer, 'SNMP responded, but the device did not identify as a printer.');
            }

            return $this->syncFromDiscovery($printer, $discovered);
        } catch (Throwable $exception) {
            $status = $this->isOfflineError($exception) ? PrinterStatus::Offline : PrinterStatus::Error;

            $printer->forceFill([
                'status' => $status,
                'last_polled_at' => now(),
                'last_error' => $exception->getMessage(),
            ])->save();

            return $printer->refresh();
        }
    }

    public function syncFromDiscovery(Printer $printer, DiscoveredPrinterData $discovered): Printer
    {
        return DB::transaction(function () use ($printer, $discovered): Printer {
            $now = Carbon::now();

            $printer->fill(array_merge(
                $discovered->toPrinterAttributes(),
                [
                    'name' => $printer->name ?: ($discovered->discoveredName ?: $printer->name),
                    'status' => PrinterStatus::Online,
                    'last_seen_at' => $now,
                    'last_polled_at' => $now,
                    'last_error' => null,
                ],
            ));
            $printer->save();

            $this->syncTonerSupplies($printer, $discovered->tonerSupplies, $now);

            return $printer->fresh(['tonerSupplies', 'tonerHistory']);
        });
    }

    public function upsertDiscoveredPrinter(DiscoveredPrinterData $discovered): Printer
    {
        $printer = Printer::query()
            ->when(
                $discovered->serialNumber,
                fn ($query) => $query->where('serial_number', $discovered->serialNumber),
                fn ($query) => $query->where('ip_address', $discovered->ipAddress),
            )
            ->first();

        if ($printer === null && $discovered->serialNumber !== null) {
            $printer = Printer::query()->where('ip_address', $discovered->ipAddress)->first();
        }

        $printer ??= new Printer([
            'ip_address' => $discovered->ipAddress,
            'name' => $discovered->discoveredName ?: $discovered->ipAddress,
        ]);

        return $this->syncFromDiscovery($printer, $discovered);
    }

    private function markOffline(Printer $printer, string $message): Printer
    {
        $printer->forceFill([
            'status' => PrinterStatus::Offline,
            'last_polled_at' => now(),
            'last_error' => $message,
        ])->save();

        return $printer->refresh();
    }

    private function isOfflineError(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timeout')
            || str_contains($message, 'no response')
            || str_contains($message, 'could not')
            || str_contains($message, 'unreachable');
    }

    /**
     * @param  array<int, array<string, mixed>>  $supplies
     */
    private function syncTonerSupplies(Printer $printer, array $supplies, Carbon $now): void
    {
        $existing = $printer->allTonerSupplies()->get();
        $seenIds = [];

        foreach ($supplies as $supplyData) {
            $normalized = $this->normalizeSupplyPayload($supplyData);
            $slotKey = $normalized['slot_key'];
            $signature = $normalized['supply_signature'];

            $this->deactivateConflictingSlotSupply($existing, $slotKey, $signature, $now, $seenIds);

            $supply = $this->findMatchingSupply(
                $existing,
                $slotKey,
                $signature,
                $normalized['color'],
                $normalized['snmp_description'],
            );
            $installedAt = $supply?->removed_at !== null || $supply?->installed_at === null
                ? $now
                : $supply->installed_at;

            $supply ??= new TonerSupply([
                'printer_id' => $printer->getKey(),
            ]);

            $supply->fill(array_merge($normalized, [
                'installed_at' => $installedAt,
                'removed_at' => null,
                'last_seen_at' => $now,
            ]));
            $supply->printer()->associate($printer);
            $supply->save();

            if (! $existing->contains(fn (TonerSupply $item): bool => $item->is($supply))) {
                $existing->push($supply);
            }

            $seenIds[] = $supply->getKey();
        }

        $printer->allTonerSupplies()
            ->whereNull('removed_at')
            ->when($seenIds !== [], fn ($query) => $query->whereNotIn('id', $seenIds))
            ->update([
                'removed_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * @param  array<string, mixed>  $supplyData
     * @return array<string, mixed>
     */
    private function normalizeSupplyPayload(array $supplyData): array
    {
        $description = $this->cleanString(Arr::get($supplyData, 'snmp_description'));
        $color = $this->cleanString(Arr::get($supplyData, 'color')) ?: 'unknown';
        $slotKey = $this->cleanString(Arr::get($supplyData, 'slot_key'))
            ?: $this->cleanString(Arr::get($supplyData, 'raw_value.slot_key'));

        $rawValue = Arr::get($supplyData, 'raw_value');
        $rawValue = is_array($rawValue) ? $rawValue : [];
        $rawValue['slot_key'] = $slotKey;

        return [
            'slot_key' => $slotKey,
            'supply_signature' => $this->makeSupplySignature($color, $description),
            'color' => $color,
            'snmp_description' => $description,
            'level' => Arr::get($supplyData, 'level'),
            'max_capacity' => Arr::get($supplyData, 'max_capacity'),
            'percentage' => Arr::get($supplyData, 'percentage'),
            'unit' => Arr::get($supplyData, 'unit'),
            'is_known' => (bool) Arr::get($supplyData, 'is_known', false),
            'raw_value' => $rawValue,
        ];
    }

    private function findMatchingSupply(
        Collection $existing,
        ?string $slotKey,
        string $signature,
        string $color,
        ?string $description,
    ): ?TonerSupply {
        $exactMatch = $existing->first(
            fn (TonerSupply $supply): bool => $supply->slot_key === $slotKey
                && $supply->supply_signature === $signature,
        );

        if ($exactMatch instanceof TonerSupply) {
            return $exactMatch;
        }

        if ($slotKey !== null) {
            $sameSlot = $existing
                ->where('slot_key', $slotKey)
                ->where('supply_signature', $signature)
                ->sortByDesc(fn (TonerSupply $supply): int => $supply->last_seen_at?->getTimestamp() ?? 0)
                ->first();

            if ($sameSlot instanceof TonerSupply) {
                return $sameSlot;
            }
        }

        $legacyMatch = $existing->first(
            fn (TonerSupply $supply): bool => $supply->supply_signature === null
                && $supply->color?->value === $color
                && $this->cleanString($supply->snmp_description) === $description,
        );

        if ($legacyMatch instanceof TonerSupply) {
            return $legacyMatch;
        }

        return $existing
            ->where('supply_signature', $signature)
            ->sortByDesc(fn (TonerSupply $supply): int => $supply->last_seen_at?->getTimestamp() ?? 0)
            ->first();
    }

    /**
     * @param  array<int, int>  $seenIds
     */
    private function deactivateConflictingSlotSupply(
        Collection $existing,
        ?string $slotKey,
        string $signature,
        Carbon $now,
        array $seenIds,
    ): void {
        if ($slotKey === null) {
            return;
        }

        $conflictingSupply = $existing->first(
            fn (TonerSupply $supply): bool => $supply->slot_key === $slotKey
                && $supply->removed_at === null
                && $supply->supply_signature !== $signature
                && ! in_array($supply->getKey(), $seenIds, true),
        );

        if (! $conflictingSupply instanceof TonerSupply) {
            return;
        }

        $conflictingSupply->forceFill([
            'removed_at' => $now,
            'updated_at' => $now,
        ])->save();
    }

    private function makeSupplySignature(string $color, ?string $description): string
    {
        $normalizedDescription = Str::lower($description ?? '');
        $normalizedDescription = preg_replace('/\s+/', ' ', trim($normalizedDescription)) ?? '';

        if ($normalizedDescription === '') {
            return $color;
        }

        return "{$color}:{$normalizedDescription}";
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
