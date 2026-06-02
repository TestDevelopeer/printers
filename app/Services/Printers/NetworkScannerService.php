<?php

namespace App\Services\Printers;

use App\Models\Printer;
use App\Services\Printers\Data\DiscoveredPrinterData;
use InvalidArgumentException;
use RuntimeException;
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

        if (count($hosts) <= 4 || ! function_exists('pcntl_fork')) {
            return $this->scanChunk($hosts, $community, $timeoutMs);
        }

        return $this->scanInParallel($hosts, $community, $timeoutMs);
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
        $scanConcurrency = max(1, (int) config('printers.scan_concurrency', 16));
        $pingConcurrency = max(1, (int) config('printers.scan_ping_concurrency', 32));
        $estimatedSnmpHosts = max(1, min($hostCount, (int) config('printers.scan_estimated_snmp_hosts', 16)));
        $estimatedSnmpSecondsPerHost = max(0.25, (float) config('printers.scan_estimated_snmp_seconds_per_host', 2));

        $pingMilliseconds = (int) ceil($hostCount / $pingConcurrency) * $timeoutMs;
        $snmpMilliseconds = (int) ceil($estimatedSnmpHosts / min($scanConcurrency, $estimatedSnmpHosts)) * (int) ceil($estimatedSnmpSecondsPerHost * 1000);
        $estimatedMilliseconds = $pingMilliseconds + $snmpMilliseconds;

        return (int) max(1, ceil($estimatedMilliseconds / 1000));
    }

    /**
     * @param  array<int, string>  $hosts
     * @return array<int, DiscoveredPrinterData>
     */
    private function scanChunk(array $hosts, string $community, int $timeoutMs): array
    {
        $results = [];

        foreach ($hosts as $ipAddress) {
            $probe = $this->printerSnmpService->probe($ipAddress, $community, $timeoutMs);

            if ($probe === null && ! $this->isHostReachable($ipAddress, $timeoutMs)) {
                continue;
            }

            $probe ??= $this->printerSnmpService->probe($ipAddress, $community, $timeoutMs);

            if ($probe === null) {
                continue;
            }

            $discovered = $this->printerSnmpService->discover($ipAddress, $community, $timeoutMs, $probe);

            if ($discovered !== null) {
                $results[] = $discovered;
            }
        }

        return $results;
    }

    /**
     * @param  array<int, string>  $hosts
     * @return array<int, DiscoveredPrinterData>
     */
    private function scanInParallel(array $hosts, string $community, int $timeoutMs): array
    {
        $workerCount = max(1, min((int) config('printers.scan_concurrency', 16), count($hosts)));
        $chunks = array_chunk($hosts, (int) ceil(count($hosts) / $workerCount));
        $tempFiles = [];
        $children = [];

        foreach ($chunks as $index => $chunk) {
            $tempFile = tempnam(sys_get_temp_dir(), 'printer-scan-');

            if ($tempFile === false) {
                throw new RuntimeException('Failed to create a temporary file for scan results.');
            }

            $tempFiles[$index] = $tempFile;
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Failed to fork scan worker process.');
            }

            if ($pid === 0) {
                try {
                    $serialized = array_map(
                        static fn (DiscoveredPrinterData $printer): array => $printer->toArray(),
                        $this->scanChunk($chunk, $community, $timeoutMs),
                    );

                    file_put_contents($tempFile, json_encode($serialized, JSON_THROW_ON_ERROR));
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }

            $children[$pid] = $tempFile;
        }

        $results = [];

        foreach ($children as $pid => $tempFile) {
            pcntl_waitpid($pid, $status);

            if (pcntl_wexitstatus($status) !== 0) {
                @unlink($tempFile);
                continue;
            }

            $content = file_get_contents($tempFile);
            @unlink($tempFile);

            if ($content === false || $content === '') {
                continue;
            }

            /** @var array<int, array<string, mixed>> $decoded */
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            foreach ($decoded as $row) {
                $results[] = DiscoveredPrinterData::fromArray($row);
            }
        }

        return $results;
    }

    private function isHostReachable(string $ipAddress, int $timeoutMs): bool
    {
        $process = new Process($this->pingCommand($ipAddress, $timeoutMs));
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @return array<int, string>
     */
    private function pingCommand(string $ipAddress, int $timeoutMs): array
    {
        return PHP_OS_FAMILY === 'Windows'
            ? ['ping', '-n', '1', '-w', (string) $timeoutMs, $ipAddress]
            : ['ping', '-c', '1', '-W', (string) max(1, (int) ceil($timeoutMs / 1000)), $ipAddress];
    }
}
