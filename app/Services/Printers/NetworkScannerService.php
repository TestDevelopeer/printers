<?php

namespace App\Services\Printers;

use App\Models\Printer;
use App\Services\Printers\Data\DiscoveredPrinterData;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

class NetworkScannerService
{
    public function __construct(
        private readonly PrinterSnmpService $printerSnmpService,
        private readonly PrinterPollingService $printerPollingService,
    ) {
    }

    /**
     * @return array<int, DiscoveredPrinterData>
     */
    public function scan(string $cidr, ?string $community = null, ?int $timeoutMs = null): array
    {
        $community ??= config('printers.default_snmp_community', 'public');
        $timeoutMs ??= config('printers.scan_timeout', 1000);

        $hosts = $this->hostsFromCidr($cidr);
        $results = [];

        foreach ($hosts as $ipAddress) {
            if (! $this->isHostReachable($ipAddress, $timeoutMs)) {
                continue;
            }

            $discovered = $this->printerSnmpService->discover($ipAddress, $community, $timeoutMs);

            if ($discovered !== null) {
                $results[] = $discovered;
            }
        }

        return $results;
    }

    /**
     * @param  array<int, DiscoveredPrinterData>  $discoveredPrinters
     * @return array<int, Printer>
     */
    public function import(array $discoveredPrinters): array
    {
        return array_map(
            fn (DiscoveredPrinterData $discovered): Printer => $this->printerPollingService->upsertDiscoveredPrinter($discovered),
            $discoveredPrinters,
        );
    }

    /**
     * @return array<int, string>
     */
    private function hostsFromCidr(string $cidr): array
    {
        if (! preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\/(\d{1,2})$/', $cidr, $matches)) {
            throw new InvalidArgumentException('CIDR must be in IPv4 format, for example 192.168.1.0/24.');
        }

        $network = ip2long($matches[1]);
        $prefix = (int) $matches[2];

        if ($network === false || $prefix < 0 || $prefix > 32) {
            throw new InvalidArgumentException('Invalid CIDR value.');
        }

        $hostCount = 2 ** (32 - $prefix);

        if ($hostCount > config('printers.scan_max_hosts', 512)) {
            throw new InvalidArgumentException('CIDR range is too large for synchronous scanning.');
        }

        $start = $network & (-1 << (32 - $prefix));
        $end = $start + $hostCount - 1;

        $hosts = [];

        for ($current = $start + 1; $current < $end; $current++) {
            $hosts[] = long2ip($current);
        }

        return $hosts;
    }

    private function isHostReachable(string $ipAddress, int $timeoutMs): bool
    {
        $command = PHP_OS_FAMILY === 'Windows'
            ? ['ping', '-n', '1', '-w', (string) $timeoutMs, $ipAddress]
            : ['ping', '-c', '1', '-W', (string) max(1, (int) ceil($timeoutMs / 1000)), $ipAddress];

        $process = new Process($command);
        $process->run();

        return $process->isSuccessful();
    }
}
