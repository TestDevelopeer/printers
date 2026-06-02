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

    public function assertCanRunSynchronously(string $cidr, ?int $timeoutMs = null): void
    {
        $timeoutMs ??= config('printers.scan_timeout', 1000);

        $hostCount = count($this->hostsFromCidr($cidr));
        $estimatedSeconds = $this->estimateScanDurationSeconds($hostCount, $timeoutMs);
        $maxSeconds = max(1, (int) config('printers.scan_max_sync_seconds', 45));

        if ($estimatedSeconds <= $maxSeconds) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'CIDR range is too large for synchronous UI scanning. Estimated duration is about %d seconds for %d hosts at %d ms timeout. Reduce the range or use the CLI command printers:scan.',
            $estimatedSeconds,
            $hostCount,
            $timeoutMs,
        ));
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

        if ($prefix === 32) {
            return [long2ip($start)];
        }

        if ($prefix === 31) {
            return [
                long2ip($start),
                long2ip($end),
            ];
        }

        $hosts = [];

        for ($current = $start + 1; $current < $end; $current++) {
            $hosts[] = long2ip($current);
        }

        return $hosts;
    }

    private function estimateScanDurationSeconds(int $hostCount, int $timeoutMs): int
    {
        $estimatedRequestsPerHost = max(1, (int) config('printers.scan_estimated_requests_per_host', 8));
        $estimatedMilliseconds = $hostCount * $timeoutMs * (1 + $estimatedRequestsPerHost);

        return (int) max(1, ceil($estimatedMilliseconds / 1000));
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
