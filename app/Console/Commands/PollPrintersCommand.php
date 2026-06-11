<?php

namespace App\Console\Commands;

use App\Jobs\PollPrinterJob;
use App\Models\Printer;
use App\Models\PrinterPollLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PollPrintersCommand extends Command
{
    protected $signature = 'printers:poll';

    protected $description = 'Поставить в очередь SNMP-опрос всех активных принтеров.';

    public function handle(): int
    {
        $this->resetStalePollingFlags();

        $count = 0;

        Printer::query()
            ->where('is_active', true)
            ->select('id')
            ->chunkById(100, function ($printers) use (&$count): void {
                foreach ($printers as $printer) {
                    PollPrinterJob::dispatch($printer->id, 'scheduled');
                    $count++;
                }
            });

        $this->info("Поставлено в очередь принтеров: {$count}.");

        return self::SUCCESS;
    }

    private function resetStalePollingFlags(): void
    {
        $threshold = Carbon::now()->subSeconds(300);

        Printer::query()
            ->where('is_polling', true)
            ->where(function ($query) use ($threshold): void {
                $query->where('manual_poll_requested_at', '<', $threshold)
                    ->orWhereNull('manual_poll_requested_at');
            })
            ->whereDoesntHave('pollLogs', fn ($query) => $query->where('status', 'running'))
            ->update([
                'is_polling' => false,
                'manual_poll_requested_at' => null,
            ]);

        PrinterPollLog::query()
            ->where('status', 'running')
            ->where('started_at', '<', $threshold)
            ->update([
                'status' => 'error',
                'message' => 'Previous poll did not finish cleanly.',
                'finished_at' => Carbon::now(),
            ]);
    }
}
