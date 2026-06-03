<?php

namespace App\Jobs;

use App\Models\Printer;
use App\Models\PrinterPollLog;
use App\Services\Printers\PrinterPollingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

class PollPrinterJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $printerId,
        public string $source = 'scheduled',
    ) {
    }

    public function handle(PrinterPollingService $printerPollingService): void
    {
        $printer = Printer::query()->find($this->printerId);

        if ($printer === null) {
            return;
        }

        $startedAt = Carbon::now();
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
            $log->forceFill([
                'status' => $this->resolveLogStatus($result),
                'printer_name' => $result->display_name,
                'printer_ip' => $result->ip_address,
                'printer_status' => $result->status?->value,
                'message' => $result->last_error,
                'finished_at' => $finishedAt,
                'duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
            ])->save();
        } catch (Throwable $exception) {
            $finishedAt = Carbon::now();

            $log->forceFill([
                'status' => 'error',
                'printer_status' => $printer->fresh()?->status?->value,
                'message' => $exception->getMessage(),
                'finished_at' => $finishedAt,
                'duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
            ])->save();

            throw $exception;
        } finally {
            $printer->forceFill([
                'is_polling' => false,
                'manual_poll_requested_at' => null,
            ])->saveQuietly();
        }
    }

    private function resolveLogStatus(Printer $printer): string
    {
        return match ($printer->status?->value) {
            'online' => 'success',
            'offline' => 'offline',
            default => 'error',
        };
    }
}
