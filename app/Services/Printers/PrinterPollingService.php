<?php

namespace App\Services\Printers;

use App\Enums\PrinterStatus;
use App\Models\Printer;
use App\Models\TonerSupply;
use App\Services\Printers\Data\DiscoveredPrinterData;
use App\Services\Printers\Data\PrinterPollResult;
use App\Services\Printers\Data\SnmpDiscoveryResult;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class PrinterPollingService
{
    public function __construct(
        private readonly PrinterSnmpService $printerSnmpService,
        private readonly PrinterAlertService $printerAlertService,
    ) {
    }

    public function poll(Printer $printer): PrinterPollResult
    {
        $previousStatus = $printer->status;
        $previousLowTonerStates = $this->snapshotLowTonerStates($printer);

        try {
            $discovery = $this->printerSnmpService->discoverWithDump(
                $printer->ip_address,
                $printer->snmp_community,
                config('printers.poll_timeout', 1000),
            );

            if ($discovery->discovered === null) {
                $printer = $this->markOffline(
                    $printer,
                    $discovery->failureReason ?? 'Устройство ответило по SNMP, но не определилось как принтер.',
                    $previousStatus,
                    $previousLowTonerStates,
                );

                return new PrinterPollResult(
                    printer: $printer,
                    rawSnmpDump: $discovery->dump,
                    normalizedPayload: $this->buildFailurePayload($discovery, $printer),
                    isPartialResponse: $discovery->isPartialResponse,
                );
            }

            $printer = $this->syncFromDiscovery(
                $printer,
                $discovery->discovered,
                $previousStatus,
                $previousLowTonerStates,
            );

            return new PrinterPollResult(
                printer: $printer,
                rawSnmpDump: $discovery->dump,
                normalizedPayload: $this->buildSuccessPayload($discovery),
                isPartialResponse: $discovery->isPartialResponse,
            );
        } catch (Throwable $exception) {
            $status = $this->isOfflineError($exception) ? PrinterStatus::Offline : PrinterStatus::Error;

            $printer->forceFill([
                'status' => $status,
                'last_polled_at' => now(),
                'is_polling' => false,
                'manual_poll_requested_at' => null,
                'last_error' => $exception->getMessage(),
            ])->save();

            $printer = $printer->fresh(['tonerSupplies', 'tonerHistory', 'allTonerSupplies']);
            $this->printerAlertService->dispatchAlerts(
                $printer,
                $previousStatus,
                $previousLowTonerStates,
            );

            return new PrinterPollResult(
                printer: $printer,
                rawSnmpDump: null,
                normalizedPayload: [
                    'discovered' => null,
                    'failure_reason' => $exception->getMessage(),
                    'printer_status' => $printer->status?->value,
                ],
                exceptionClass: $exception::class,
                isPartialResponse: false,
            );
        }
    }

    /**
     * @param  array<string, bool>|null  $previousLowTonerStates
     */
    public function syncFromDiscovery(
        Printer $printer,
        DiscoveredPrinterData $discovered,
        ?PrinterStatus $previousStatus = null,
        ?array $previousLowTonerStates = null,
    ): Printer {
        $previousStatus ??= $printer->status;
        $previousLowTonerStates ??= $this->snapshotLowTonerStates($printer);

        $result = DB::transaction(function () use ($printer, $discovered): array {
            $now = Carbon::now();

            $printer->fill(array_merge(
                $discovered->toPrinterAttributes(),
                [
                    'name' => $printer->name ?: ($discovered->discoveredName ?: $printer->name),
                    'status' => PrinterStatus::Online,
                    'last_seen_at' => $now,
                    'last_polled_at' => $now,
                    'is_polling' => false,
                    'manual_poll_requested_at' => null,
                    'last_error' => null,
                ],
            ));
            $printer->save();

            $syncResult = $this->syncTonerSupplies($printer, $discovered->tonerSupplies, $now);

            return [
                'printer' => $printer->fresh(['tonerSupplies', 'tonerHistory', 'allTonerSupplies']),
                'replacements' => $syncResult['replacements'],
            ];
        });

        $printer = $result['printer'];

        $this->printerAlertService->dispatchAlerts(
            $printer,
            $previousStatus,
            $previousLowTonerStates,
            $result['replacements'],
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

    /**
     * @return array<string, mixed>
     */
    private function buildSuccessPayload(SnmpDiscoveryResult $discovery): array
    {
        return [
            'discovered' => $discovery->discovered?->toArray(),
            'failure_reason' => null,
            'printer_status' => PrinterStatus::Online->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFailurePayload(SnmpDiscoveryResult $discovery, Printer $printer): array
    {
        return [
            'discovered' => null,
            'failure_reason' => $discovery->failureReason,
            'printer_status' => $printer->status?->value,
        ];
    }

    /**
     * @param  array<string, bool>|null  $previousLowTonerStates
     */
    private function markOffline(
        Printer $printer,
        string $message,
        ?PrinterStatus $previousStatus = null,
        ?array $previousLowTonerStates = null,
    ): Printer {
        $printer->forceFill([
            'status' => PrinterStatus::Offline,
            'last_polled_at' => now(),
            'is_polling' => false,
            'manual_poll_requested_at' => null,
            'last_error' => $message,
        ])->save();

        $printer = $printer->fresh(['tonerSupplies', 'tonerHistory', 'allTonerSupplies']);
        $this->printerAlertService->dispatchAlerts(
            $printer,
            $previousStatus ?? $printer->status,
            $previousLowTonerStates ?? $this->snapshotLowTonerStates($printer),
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
     * @return array{replacements: array<int, array{slot_key: string, supply: TonerSupply}>}
     */
    private function syncTonerSupplies(Printer $printer, array $supplies, Carbon $now): array
    {
        $existing = $printer->allTonerSupplies()->get();
        $normalizedSupplies = collect($supplies)
            ->map(fn (array $supplyData): array => $this->normalizeSupplyPayload($supplyData))
            ->filter(fn (array $supply): bool => filled($supply['slot_key']))
            ->values();

        $snmpSlotKeys = $normalizedSupplies
            ->pluck('slot_key')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $detectedReplacements = [];

        foreach ($normalizedSupplies as $normalized) {
            $slotKey = (string) $normalized['slot_key'];
            $activeSupply = $this->findActiveSupplyBySlot($existing, $slotKey);

            if ($activeSupply === null) {
                $supply = $this->createActiveSupply($printer, $normalized, $now, confirmed: true);
                $existing->push($supply);

                continue;
            }

            if ($activeSupply->needs_identity_confirmation) {
                $this->updateSupplySnmpData($activeSupply, $normalized, $now);

                continue;
            }

            if ($this->isReplacementDetected($activeSupply, $normalized)) {
                $this->moveSupplyToHistory($activeSupply, $now);

                $provisional = $this->createActiveSupply($printer, $normalized, $now, confirmed: false);
                $existing->push($provisional);

                $detectedReplacements[] = [
                    'slot_key' => $slotKey,
                    'supply' => $provisional,
                ];

                continue;
            }

            $this->updateSupplySnmpData($activeSupply, $normalized, $now);
        }

        $printer->allTonerSupplies()
            ->whereNull('removed_at')
            ->get()
            ->each(function (TonerSupply $activeSupply) use ($snmpSlotKeys, $now): void {
                if (! in_array($activeSupply->slot_key, $snmpSlotKeys, true)) {
                    $this->moveSupplyToHistory($activeSupply, $now);
                }
            });

        return ['replacements' => $detectedReplacements];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function createActiveSupply(
        Printer $printer,
        array $normalized,
        Carbon $now,
        bool $confirmed,
    ): TonerSupply {
        $supply = new TonerSupply([
            'printer_id' => $printer->getKey(),
        ]);

        $payload = array_merge($normalized, [
            'installed_at' => $now,
            'removed_at' => null,
            'history_slot_key' => null,
            'last_seen_at' => $now,
            'is_on_service' => false,
            'needs_identity_confirmation' => ! $confirmed,
            'replacement_detected_at' => $confirmed ? null : $now,
            'color' => $normalized['detected_color'],
        ]);

        $supply->fill($payload);
        $supply->printer()->associate($printer);
        $supply->save();

        return $supply;
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function updateSupplySnmpData(TonerSupply $supply, array $normalized, Carbon $now): void
    {
        $payload = [
            'snmp_description' => $normalized['snmp_description'],
            'level' => $normalized['level'],
            'max_capacity' => $normalized['max_capacity'],
            'percentage' => $normalized['percentage'],
            'unit' => $normalized['unit'],
            'is_known' => $normalized['is_known'],
            'raw_value' => $normalized['raw_value'],
            'detected_color' => $normalized['detected_color'],
            'last_seen_at' => $now,
        ];

        if (! $supply->is_color_manual) {
            $payload['color'] = $normalized['detected_color'];
        }

        $supply->forceFill($payload)->save();
    }

    private function moveSupplyToHistory(TonerSupply $supply, Carbon $now): void
    {
        $supply->forceFill([
            'removed_at' => $now,
            'history_slot_key' => $supply->slot_key,
            'is_on_service' => true,
            'needs_identity_confirmation' => false,
            'replacement_detected_at' => null,
            'updated_at' => $now,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function isReplacementDetected(TonerSupply $activeSupply, array $normalized): bool
    {
        if (! $activeSupply->is_known || $activeSupply->percentage === null) {
            return false;
        }

        if (! $normalized['is_known'] || $normalized['percentage'] === null) {
            return false;
        }

        $minIncrease = (int) config('printers.replacement_detection_min_increase', 3);

        return ((int) $normalized['percentage'] - (int) $activeSupply->percentage) >= $minIncrease;
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
        $observedColor = $this->cleanString(Arr::get($rawValue, 'colorant_value'));

        return [
            'slot_key' => $slotKey,
            'color' => $color,
            'detected_color' => $color,
            'snmp_description' => $description,
            'level' => Arr::get($supplyData, 'level'),
            'max_capacity' => Arr::get($supplyData, 'max_capacity'),
            'percentage' => Arr::get($supplyData, 'percentage'),
            'unit' => Arr::get($supplyData, 'unit'),
            'is_known' => (bool) Arr::get($supplyData, 'is_known', false),
            'raw_value' => array_merge($rawValue, [
                'observed_color' => $observedColor,
            ]),
        ];
    }

    private function findActiveSupplyBySlot(EloquentCollection $existing, string $slotKey): ?TonerSupply
    {
        return $existing
            ->first(
                fn (TonerSupply $supply): bool => $supply->removed_at === null
                    && $supply->slot_key === $slotKey,
            );
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
            ->whereNull('removed_at')
            ->get()
            ->mapWithKeys(fn (TonerSupply $supply): array => [$supply->identity_key => $supply->isLow()])
            ->all();
    }
}
