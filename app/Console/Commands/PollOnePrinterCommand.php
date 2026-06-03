<?php

namespace App\Console\Commands;

use App\Jobs\PollPrinterJob;
use App\Models\Printer;
use App\Services\Printers\PrinterPollingService;
use Illuminate\Console\Command;

class PollOnePrinterCommand extends Command
{
    protected $signature = 'printers:poll-one {printer_id}';

    protected $description = 'Опросить один принтер немедленно.';

    public function handle(PrinterPollingService $printerPollingService): int
    {
        $printer = Printer::query()->find($this->argument('printer_id'));

        if ($printer === null) {
            $this->error('Принтер не найден.');

            return self::FAILURE;
        }

        (new PollPrinterJob($printer->id, 'cli'))->handle($printerPollingService);

        $status = $printer->fresh()?->status?->value ?? 'unknown';
        $this->info(sprintf('Принтер %s опрошен, статус: %s.', $printer->display_name, $status));

        return self::SUCCESS;
    }
}
