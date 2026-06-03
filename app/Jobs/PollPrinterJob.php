<?php

namespace App\Jobs;

use App\Models\Printer;
use App\Services\Printers\PrinterPollingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollPrinterJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $printerId,
    ) {
    }

    public function handle(PrinterPollingService $printerPollingService): void
    {
        $printer = Printer::query()->find($this->printerId);

        if ($printer === null) {
            return;
        }

        try {
            $printerPollingService->poll($printer);
        } finally {
            $printer->forceFill([
                'is_polling' => false,
            ])->saveQuietly();
        }
    }
}
