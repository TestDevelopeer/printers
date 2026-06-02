<?php

namespace App\Console\Commands;

use App\Services\Printers\NetworkScannerService;
use Illuminate\Console\Command;

class ScanPrintersCommand extends Command
{
    protected $signature = 'printers:scan {cidr} {--community=public}';

    protected $description = 'Просканировать локальный CIDR-диапазон по SNMP и импортировать найденные принтеры.';

    public function handle(NetworkScannerService $networkScannerService): int
    {
        $cidr = (string) $this->argument('cidr');
        $community = (string) $this->option('community');

        $this->info("Сканирование {$cidr}...");

        $discovered = $networkScannerService->scan($cidr, $community);
        $imported = $networkScannerService->import($discovered);

        $this->info(sprintf('Найдено принтеров: %d, импортировано: %d.', count($discovered), count($imported)));

        return self::SUCCESS;
    }
}
