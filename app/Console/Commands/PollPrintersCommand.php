<?php

namespace App\Console\Commands;

use App\Jobs\PollPrinterJob;
use App\Models\Printer;
use Illuminate\Console\Command;

class PollPrintersCommand extends Command
{
    protected $signature = 'printers:poll';

    protected $description = 'Queue SNMP polling for all active printers.';

    public function handle(): int
    {
        $count = 0;

        Printer::query()
            ->where('is_active', true)
            ->select('id')
            ->chunkById(100, function ($printers) use (&$count): void {
                foreach ($printers as $printer) {
                    PollPrinterJob::dispatch($printer->id);
                    $count++;
                }
            });

        $this->info("Queued polling for {$count} printers.");

        return self::SUCCESS;
    }
}
