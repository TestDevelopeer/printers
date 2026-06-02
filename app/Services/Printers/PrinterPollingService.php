<?php

namespace App\Services\Printers;

use App\Enums\PrinterStatus;
use App\Models\Printer;
use App\Services\Printers\Data\DiscoveredPrinterData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
            $printer->fill(array_merge(
                $discovered->toPrinterAttributes(),
                [
                    'name' => $printer->name ?: ($discovered->discoveredName ?: $printer->name),
                    'status' => PrinterStatus::Online,
                    'last_seen_at' => Carbon::now(),
                    'last_polled_at' => Carbon::now(),
                    'last_error' => null,
                ],
            ));
            $printer->save();

            $printer->tonerSupplies()->delete();
            $printer->tonerSupplies()->createMany($discovered->tonerSupplies);

            return $printer->fresh(['tonerSupplies', 'cartridgeSets.cartridges']);
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
}
