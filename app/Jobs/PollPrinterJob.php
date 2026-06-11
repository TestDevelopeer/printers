<?php

namespace App\Jobs;

use App\Models\Printer;
use App\Models\PrinterPollLog;
use App\Services\Printers\Data\PrinterPollResult;
use App\Services\Printers\PrinterPollingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Throwable;

class PollPrinterJob implements ShouldQueue
{
    use Queueable;

    private const OVERLAP_SECONDS = 300;

    public function __construct(
        public int $printerId,
        public string $source = 'scheduled',
    ) {
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("printer-poll:{$this->printerId}"))
                ->expireAfter(self::OVERLAP_SECONDS)
                ->releaseAfter(30),
        ];
    }

    public function handle(PrinterPollingService $printerPollingService): void
    {
        $printer = Printer::query()->find($this->printerId);

        if ($printer === null) {
            return;
        }

        $startedAt = Carbon::now();
        $this->closeDanglingLogs($printer, $startedAt);
        $this->resetStalePollingState($printer);
        $printer->refresh();

        $log = PrinterPollLog::query()->create([
            'printer_id' => $printer->id,
            'source' => $this->source,
            'status' => 'running',
            'printer_name' => $printer->display_name,
            'printer_ip' => $printer->ip_address,
            'started_at' => $startedAt,
        ]);

        try {
            $result = $printerPollingService->poll($printer);

            $finishedAt = Carbon::now();
            $this->fillLogFromResult($log, $result, $startedAt, $finishedAt);
        } catch (Throwable $exception) {
            $finishedAt = Carbon::now();

            $log->forceFill([
                'status' => 'error',
                'printer_status' => $printer->fresh()?->status?->value,
                'message' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'finished_at' => $finishedAt,
                'duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
            ])->save();

            throw $exception;
        } finally {
            Printer::query()
                ->whereKey($this->printerId)
                ->update([
                    'is_polling' => false,
                    'manual_poll_requested_at' => null,
                ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Printer::query()
            ->whereKey($this->printerId)
            ->update([
                'is_polling' => false,
                'manual_poll_requested_at' => null,
            ]);
    }

    private function fillLogFromResult(
        PrinterPollLog $log,
        PrinterPollResult $result,
        Carbon $startedAt,
        Carbon $finishedAt,
    ): void {
        $printer = $result->printer;

        $log->forceFill([
            'status' => $this->resolveLogStatus($printer),
            'printer_name' => $printer->display_name,
            'printer_ip' => $printer->ip_address,
            'printer_status' => $printer->status?->value,
            'message' => $printer->last_error,
            'raw_snmp_dump' => $result->rawSnmpDump,
            'normalized_payload' => $result->normalizedPayload,
            'exception_class' => $result->exceptionClass,
            'is_partial_response' => $result->isPartialResponse,
            'finished_at' => $finishedAt,
            'duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
        ])->save();
    }

    private function resolveLogStatus(Printer $printer): string
    {
        return match ($printer->status?->value) {
            'online' => 'success',
            'offline' => 'offline',
            default => 'error',
        };
    }

    private function closeDanglingLogs(Printer $printer, Carbon $finishedAt): void
    {
        $runningLogs = PrinterPollLog::query()
            ->where('printer_id', $printer->getKey())
            ->where('status', 'running')
            ->get();

        foreach ($runningLogs as $runningLog) {
            $durationMs = $runningLog->started_at instanceof Carbon
                ? $runningLog->started_at->diffInMilliseconds($finishedAt)
                : null;

            $runningLog->forceFill([
                'status' => 'error',
                'printer_status' => $printer->status?->value,
                'message' => 'Previous poll did not finish cleanly.',
                'finished_at' => $finishedAt,
                'duration_ms' => $durationMs,
            ])->save();
        }

        if ($runningLogs->isNotEmpty()) {
            Printer::query()
                ->whereKey($printer->getKey())
                ->update([
                    'is_polling' => false,
                    'manual_poll_requested_at' => null,
                ]);
        }
    }

    private function resetStalePollingState(Printer $printer): void
    {
        if (! $printer->is_polling) {
            return;
        }

        $hasRunningLog = PrinterPollLog::query()
            ->where('printer_id', $printer->getKey())
            ->where('status', 'running')
            ->exists();

        if ($hasRunningLog) {
            return;
        }

        $printer->forceFill([
            'is_polling' => false,
            'manual_poll_requested_at' => null,
        ])->save();
    }
}
