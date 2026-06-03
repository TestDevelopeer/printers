<?php

namespace App\Services\Printers;

use App\Enums\PrinterStatus;
use App\Models\Printer;
use App\Models\TonerSupply;
use App\Services\Printers\Data\DiscoveredPrinterData;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class PrinterPollingService
{
    public function __construct(
        private readonly PrinterSnmpService $printerSnmpService,
        private readonly PrinterAlertService $printerAlertService,
    ) {
    }

    public function poll(Printer $printer): Printer
    {
        $previousStatus = $printer->status;
        $previousLowTonerStates = $this->snapshotLowTonerStates($printer);
        $previousActiveSupplies = $this->snapshotActiveSupplies($printer);

        try {
            $discovered = $this->printerSnmpService->discover(
                $printer->ip_address,
                $printer->snmp_community,
                config('printers.poll_timeout', 1000),
            );

            if ($discovered === null) {
                return $this->markOffline(
                    $printer,
                    'Устройство ответило по SNMP, но не определилось как принтер.',
                    $previousStatus,
                    $previousLowTonerStates,
                    $previousActiveSupplies,
                );
            }

            return $this->syncFromDiscovery(
                $printer,
                $discovered,
                $previousStatus,
                $previousLowTonerStates,
                $previousActiveSupplies,
            );
        } catch (Throwable $exception) {
            $status = $this->isOfflineError($exception) ? PrinterStatus::Offline : PrinterStatus::Error;

            $printer->forceFill([
                'status' => $status,
                'last_polled_at' => now(),
                'is_polling' => false,
                'last_error' => $exception->getMessage(),
            ])->save();

            $printer = $printer->fresh(['tonerSupplies', 'tonerHistory', 'allTonerSupplies']);
            $this->printerAlertService->dispatchAlerts(
                $printer,
                $previousStatus,
                $previousLowTonerStates,
                $previousActiveSupplies,
            );

            return $printer;
        }
    }

    /**
     * @param  array<string, bool>|null  $previousLowTonerStates
     * @param  array<string, array{color: string, description: string|null}>|null  $previousActiveSupplies
     */
    public function syncFromDiscovery(
        Printer $printer,
        DiscoveredPrinterData $discovered,
        ?PrinterStatus $previousStatus = null,
        ?array $previousLowTonerStates = null,
        ?array $previousActiveSupplies = null,
    ): Printer {
        $previousStatus ??= $printer->status;
        $previousLowTonerStates ??= $this->snapshotLowTonerStates($printer);
        $previousActiveSupplies ??= $this->snapshotActiveSupplies($printer);
        $pendingTransferSupplyIds = [];

        $result = DB::transaction(function () use ($printer, $discovered, &$pendingTransferSupplyIds): array {
            $now = Carbon::now();

            $printer->fill(array_merge(
                $discovered->toPrinterAttributes(),
                [
                    'name' => $printer->name ?: ($discovered->discoveredName ?: $printer->name),
                    'status' => PrinterStatus::Online,
                    'last_seen_at' => $now,
                    'last_polled_at' => $now,
                    'is_polling' => false,
                    'last_error' => null,
                ],
            ));
            $printer->save();

            $pendingTransferSupplyIds = $this->syncTonerSupplies($printer, $discovered->tonerSupplies, $now);

            return [
                'printer' => $printer->fresh(['tonerSupplies', 'tonerHistory', 'allTonerSupplies']),
                'pending_transfer_supply_ids' => $pendingTransferSupplyIds,
            ];
        });
        $printer = $result['printer'];

        foreach ($result['pending_transfer_supply_ids'] as $supplyId) {
            $supply = TonerSupply::query()->with(['printer', 'transferTargetPrinter'])->find($supplyId);

            if (! $supply instanceof TonerSupply) {
                continue;
            }

            $targetPrinter = $supply->transferTargetPrinter;

            if (! $targetPrinter instanceof Printer) {
                continue;
            }

            $this->printerAlertService->notifyForeignSupplyDetected($targetPrinter, $supply);
        }

        $this->printerAlertService->dispatchAlerts(
            $printer,
            $previousStatus,
            $previousLowTonerStates,
            $previousActiveSupplies,
        );

        return $printer;
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

    public function confirmPendingTransfer(TonerSupply $supply): TonerSupply
    {
        return DB::transaction(function () use ($supply): TonerSupply {
            $supply = $supply->fresh(['printer', 'transferTargetPrinter']);

            if (! $supply instanceof TonerSupply || ! $supply->needsTransferConfirmation()) {
                return $supply;
            }

            $targetPrinter = $supply->transferTargetPrinter;

            if (! $targetPrinter instanceof Printer) {
                return $supply;
            }

            $previousPrinter = $supply->printer;

            if (! $previousPrinter instanceof Printer) {
                return $supply;
            }

            $supply->printer()->associate($targetPrinter);
            $supply->forceFill([
                'removed_at' => null,
                'installed_at' => $supply->installed_at ?? now(),
                'is_on_service' => false,
                'transfer_target_printer_id' => null,
                'transfer_detected_at' => null,
            ])->save();

            $supply = $supply->fresh(['printer', 'transferTargetPrinter']);
            $this->printerAlertService->notifyTransferConfirmed($supply, $previousPrinter, $targetPrinter);

            return $supply;
        });
    }

    /**
     * @param  array<string, bool>|null  $previousLowTonerStates
     * @param  array<string, array{color: string, description: string|null}>|null  $previousActiveSupplies
     */
    private function markOffline(
        Printer $printer,
        string $message,
        ?PrinterStatus $previousStatus = null,
        ?array $previousLowTonerStates = null,
        ?array $previousActiveSupplies = null,
    ): Printer {
        $printer->forceFill([
            'status' => PrinterStatus::Offline,
            'last_polled_at' => now(),
            'is_polling' => false,
            'last_error' => $message,
        ])->save();

        $printer = $printer->fresh(['tonerSupplies', 'tonerHistory', 'allTonerSupplies']);
        $this->printerAlertService->dispatchAlerts(
            $printer,
            $previousStatus ?? $printer->status,
            $previousLowTonerStates ?? $this->snapshotLowTonerStates($printer),
            $previousActiveSupplies ?? $this->snapshotActiveSupplies($printer),
        );

        return $printer;
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
    private function syncTonerSupplies(Printer $printer, array $supplies, Carbon $now): array
    {
        $existing = $printer->allTonerSupplies()->get();
        $normalizedSupplies = collect($supplies)
            ->map(fn (array $supplyData): array => $this->normalizeSupplyPayload($supplyData))
            ->filter(fn (array $supply): bool => filled($supply['supply_signature']))
            ->values();

        $seenIds = [];
        $pendingSignatures = [];
        $newPendingTransferSupplyIds = [];

        foreach ($normalizedSupplies as $normalized) {
            $slotKey = $normalized['slot_key'];
            $signature = $normalized['supply_signature'];

            $this->deactivateConflictingSlotSupply($existing, $slotKey, $signature, $now, $seenIds);

            $ownSupply = $this->findOwnMatchingSupply($existing, $signature);

            if ($ownSupply instanceof TonerSupply) {
                $this->syncOwnedSupply($printer, $ownSupply, $normalized, $now);
                $seenIds[] = $ownSupply->getKey();
                continue;
            }

            $foreignSupply = $this->findForeignMatchingSupply($signature, $printer->getKey());

            if ($foreignSupply instanceof TonerSupply) {
                $isNewPending = $this->markPendingTransfer($foreignSupply, $printer, $normalized, $now);
                $pendingSignatures[] = $signature;

                if ($isNewPending) {
                    $newPendingTransferSupplyIds[] = $foreignSupply->getKey();
                }

                continue;
            }

            $newSupply = new TonerSupply([
                'printer_id' => $printer->getKey(),
            ]);

            $this->syncOwnedSupply($printer, $newSupply, $normalized, $now);
            $existing->push($newSupply);
            $seenIds[] = $newSupply->getKey();
        }

        $toHistory = $printer->allTonerSupplies()
            ->whereNull('removed_at')
            ->when($seenIds !== [], fn ($query) => $query->whereNotIn('id', $seenIds))
            ->get();

        foreach ($toHistory as $supply) {
            $supply->forceFill([
                'removed_at' => $now,
                'is_on_service' => true,
                'updated_at' => $now,
            ])->save();
        }

        $this->clearStalePendingTransfers($printer, $pendingSignatures);

        return $newPendingTransferSupplyIds;
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function syncOwnedSupply(Printer $printer, TonerSupply $supply, array $normalized, Carbon $now): void
    {
        $wasInHistory = $supply->removed_at !== null;
        $installedAt = $wasInHistory || $supply->installed_at === null
            ? $now
            : $supply->installed_at;

        $payload = array_merge($normalized, [
            'installed_at' => $installedAt,
            'removed_at' => null,
            'last_seen_at' => $now,
            'transfer_target_printer_id' => null,
            'transfer_detected_at' => null,
        ]);

        if ($supply->is_color_manual) {
            unset($payload['color']);
        } else {
            $payload['color'] = $normalized['detected_color'];
        }

        if ($wasInHistory || $supply->needsTransferConfirmation()) {
            $payload['is_on_service'] = false;
        }

        $supply->fill($payload);
        $supply->printer()->associate($printer);
        $supply->save();
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function markPendingTransfer(TonerSupply $supply, Printer $targetPrinter, array $normalized, Carbon $now): bool
    {
        $isNewPending = $supply->transfer_target_printer_id !== $targetPrinter->getKey();
        $payload = [
            'slot_key' => $normalized['slot_key'],
            'supply_signature' => $normalized['supply_signature'],
            'detected_color' => $normalized['detected_color'],
            'snmp_description' => $normalized['snmp_description'],
            'level' => $normalized['level'],
            'max_capacity' => $normalized['max_capacity'],
            'percentage' => $normalized['percentage'],
            'unit' => $normalized['unit'],
            'is_known' => $normalized['is_known'],
            'raw_value' => $normalized['raw_value'],
            'last_seen_at' => $now,
            'transfer_target_printer_id' => $targetPrinter->getKey(),
            'transfer_detected_at' => $now,
        ];

        if (! $supply->is_color_manual) {
            $payload['color'] = $normalized['detected_color'];
        }

        $supply->fill($payload);
        $supply->save();

        return $isNewPending;
    }

    private function clearStalePendingTransfers(Printer $printer, array $pendingSignatures): void
    {
        $query = TonerSupply::query()->where('transfer_target_printer_id', $printer->getKey());

        if ($pendingSignatures !== []) {
            $query->whereNotIn('supply_signature', $pendingSignatures);
        }

        foreach ($query->get() as $supply) {
            $supply->forceFill([
                'transfer_target_printer_id' => null,
                'transfer_detected_at' => null,
            ])->save();
        }
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
            'detected_color' => $color,
            'snmp_description' => $description,
            'level' => Arr::get($supplyData, 'level'),
            'max_capacity' => Arr::get($supplyData, 'max_capacity'),
            'percentage' => Arr::get($supplyData, 'percentage'),
            'unit' => Arr::get($supplyData, 'unit'),
            'is_known' => (bool) Arr::get($supplyData, 'is_known', false),
            'raw_value' => $rawValue,
        ];
    }

    private function findOwnMatchingSupply(EloquentCollection $existing, string $signature): ?TonerSupply
    {
        return $existing
            ->filter(fn (TonerSupply $supply): bool => $supply->supply_signature === $signature)
            ->sortByDesc(fn (TonerSupply $supply): int => $supply->removed_at === null ? 1 : 0)
            ->sortByDesc(fn (TonerSupply $supply): int => $supply->last_seen_at?->getTimestamp() ?? 0)
            ->first();
    }

    private function findForeignMatchingSupply(string $signature, int $printerId): ?TonerSupply
    {
        return TonerSupply::query()
            ->with(['printer', 'transferTargetPrinter'])
            ->where('supply_signature', $signature)
            ->where('printer_id', '!=', $printerId)
            ->get()
            ->sortByDesc(fn (TonerSupply $supply): int => $supply->removed_at === null ? 1 : 0)
            ->sortByDesc(fn (TonerSupply $supply): int => $supply->last_seen_at?->getTimestamp() ?? 0)
            ->first();
    }

    /**
     * @param  array<int, int>  $seenIds
     */
    private function deactivateConflictingSlotSupply(
        EloquentCollection $existing,
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
            'is_on_service' => true,
            'updated_at' => $now,
        ])->save();
    }

    private function makeSupplySignature(string $color, ?string $description): string
    {
        return TonerSupply::buildSupplySignature($color, $description);
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, bool>
     */
    private function snapshotLowTonerStates(Printer $printer): array
    {
        if (! $printer->exists) {
            return [];
        }

        return $printer->allTonerSupplies()
            ->get()
            ->mapWithKeys(fn (TonerSupply $supply): array => [$supply->identity_key => $supply->isLow()])
            ->all();
    }

    /**
     * @return array<string, array{color: string, description: string|null}>
     */
    private function snapshotActiveSupplies(Printer $printer): array
    {
        if (! $printer->exists) {
            return [];
        }

        return $printer->tonerSupplies()
            ->get()
            ->filter(fn (TonerSupply $supply): bool => filled($supply->slot_key))
            ->mapWithKeys(fn (TonerSupply $supply): array => [
                $supply->slot_key => [
                    'color' => $supply->color_label,
                    'description' => $supply->snmp_description,
                ],
            ])
            ->all();
    }
}
