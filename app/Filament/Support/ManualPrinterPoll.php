<?php

namespace App\Filament\Support;

use App\Jobs\PollPrinterJob;
use App\Models\Printer;
use Throwable;

class ManualPrinterPoll
{
    /**
     * @throws Throwable
     */
    public static function run(Printer $printer, bool $createProvisionalForEmptySlots = false): void
    {
        $printer->forceFill([
            'is_polling' => true,
            'manual_poll_requested_at' => now(),
        ])->save();

        PollPrinterJob::dispatchSync($printer->getKey(), 'manual', $createProvisionalForEmptySlots);

        $printer->refresh();
    }
}
