<?php

namespace App\Console\Commands;

use App\Models\Printer;
use App\Services\Printers\PrinterPollingService;
use Illuminate\Console\Command;

class PollOnePrinterCommand extends Command
{
    protected $signature = 'printers:poll-one {printer_id}';

    protected $description = 'Poll a single printer immediately.';

    public function handle(PrinterPollingService $printerPollingService): int
    {
        $printer = Printer::query()->find($this->argument('printer_id'));

        if ($printer === null) {
            $this->error('Printer not found.');

            return self::FAILURE;
        }

        $printerPollingService->poll($printer);

        $this->info(sprintf(
            'Printer %s polled with status %s.',
            $printer->display_name,
            $printer->fresh()->status->value,
        ));

        return self::SUCCESS;
    }
}
