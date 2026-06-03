<?php

namespace App\Console\Commands;

use App\Jobs\PollPrinterJob;
use App\Models\Printer;
use Illuminate\Console\Command;

class PollPrintersCommand extends Command
{
    protected $signature = 'printers:poll';

    protected $description = 'Поставить в очередь SNMP-опрос всех активных принтеров.';

    public function handle(): int
    {
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
}
