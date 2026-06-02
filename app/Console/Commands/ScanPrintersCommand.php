<?php

namespace App\Console\Commands;

use App\Services\Printers\NetworkScannerService;
use Illuminate\Console\Command;

class ScanPrintersCommand extends Command
{
    protected $signature = 'printers:scan {cidr} {--community=public}';

    protected $description = 'Scan a local CIDR range for SNMP printers and import them into the database.';

    public function handle(NetworkScannerService $networkScannerService): int
    {
        $cidr = (string) $this->argument('cidr');
        $community = (string) $this->option('community');

        $this->info("Scanning {$cidr}...");

        $discovered = $networkScannerService->scan($cidr, $community);
        $imported = $networkScannerService->import($discovered);

        $this->info(sprintf('Found %d printers, imported %d.', count($discovered), count($imported)));

        return self::SUCCESS;
    }
}
