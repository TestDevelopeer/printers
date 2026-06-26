<?php

namespace App\Console\Commands;

use App\Services\Printers\MeterReadingService;
use Illuminate\Console\Command;

class RecordDailyMeterSnapshotCommand extends Command
{
    protected $signature = 'printers:daily-meter-snapshot';

    protected $description = 'Снять суточный снимок показаний счётчиков для всех активных принтеров.';

    public function handle(MeterReadingService $meterReadingService): int
    {
        $count = $meterReadingService->takeDailySnapshot();

        $this->info("Снимок счётчиков создан для принтеров: {$count}.");

        return self::SUCCESS;
    }
}