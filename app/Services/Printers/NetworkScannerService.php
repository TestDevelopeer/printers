<?php

namespace App\Services\Printers;

use App\Models\Printer;
use App\Services\Printers\Data\DiscoveredPrinterData;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
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

        if (count($hosts) <= 1) {
            return $this->scanHosts($hosts, $community, $timeoutMs);
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
            'CIDR-диапазон слишком большой для синхронного сканирования в интерфейсе. Оценка: около %d секунд для %d хостов при таймауте %d мс. Уменьшите диапазон или используйте CLI-команду printers:scan.',
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
     * @param  array<int, string>  $hosts
     * @return array<int, DiscoveredPrinterData>
     */
    public function scanHosts(array $hosts, string $community, int $timeoutMs): array
    {
        $results = [];

        foreach ($hosts as $ipAddress) {
            $discovered = $this->printerSnmpService->discover($ipAddress, $community, $timeoutMs);

            if ($discovered !== null) {
                $results[] = $discovered;
            }
        }

        return $results;
    }

    /**
     * @return array<int, string>
     */
    private function hostsFromCidr(string $cidr): array
    {
        if (! preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\/(\d{1,2})$/', $cidr, $matches)) {
            throw new InvalidArgumentException('CIDR должен быть в формате IPv4, например 192.168.1.0/24.');
        }

        $network = ip2long($matches[1]);
        $prefix = (int) $matches[2];

        if ($network === false || $prefix < 0 || $prefix > 32) {
            throw new InvalidArgumentException('Некорректное значение CIDR.');
        }

        $hostCount = 2 ** (32 - $prefix);

        if ($hostCount > config('printers.scan_max_hosts', 512)) {
            throw new InvalidArgumentException('CIDR-диапазон слишком большой для синхронного сканирования.');
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
    private function scanInParallel(array $hosts, string $community, int $timeoutMs): array
    {
        $workerCount = max(1, min((int) config('printers.scan_concurrency', 16), count($hosts)));
        $chunks = array_chunk($hosts, (int) ceil(count($hosts) / $workerCount));

        if ($this->shouldScanChunksInProcessPool()) {
            return $this->scanChunksWithProcessPool($chunks, $community, $timeoutMs);
        }

        $results = [];

        foreach ($chunks as $chunk) {
            $results = array_merge(
                $results,
                $this->scanHosts($chunk, $community, $timeoutMs),
            );
        }

        return $results;
    }

    /**
     * @param  array<int, array<int, string>>  $chunks
     * @return array<int, DiscoveredPrinterData>
     */
    private function scanChunksWithProcessPool(array $chunks, string $community, int $timeoutMs): array
    {
        $phpBinary = (new PhpExecutableFinder())->find(false) ?: 'php';
        $artisan = base_path('artisan');
        $maxWorkers = max(1, min((int) config('printers.scan_concurrency', 16), count($chunks)));
        $running = [];
        $results = [];
        $chunkIndex = 0;
        $chunkTimeoutSeconds = $this->chunkProcessTimeoutSeconds($chunks, $timeoutMs);

        while ($chunkIndex < count($chunks) || $running !== []) {
            while (count($running) < $maxWorkers && $chunkIndex < count($chunks)) {
                $payload = json_encode([
                    'hosts' => $chunks[$chunkIndex],
                    'community' => $community,
                    'timeout' => $timeoutMs,
                ], JSON_THROW_ON_ERROR);

                $process = new Process([$phpBinary, $artisan, 'printers:scan-chunk'], base_path(), null, $payload);
                $process->setTimeout($chunkTimeoutSeconds);
                $process->start();

                $running[] = $process;
                $chunkIndex++;
            }

            foreach ($running as $index => $process) {
                if ($process->isRunning()) {
                    continue;
                }

                if ($process->isSuccessful()) {
                    $results = array_merge($results, $this->decodeChunkProcessOutput($process->getOutput()));
                }

                unset($running[$index]);
            }

            if ($running !== []) {
                usleep(50_000);
            }
        }

        return $results;
    }

    /**
     * @param  array<int, array<int, string>>  $chunks
     */
    private function chunkProcessTimeoutSeconds(array $chunks, int $timeoutMs): int
    {
        $largestChunk = max(array_map('count', $chunks));

        return max(30, (int) ceil($largestChunk * ($timeoutMs / 1000 + 1)) + 10);
    }

    /**
     * @return array<int, DiscoveredPrinterData>
     */
    private function decodeChunkProcessOutput(string $output): array
    {
        $output = trim($output);

        if ($output === '') {
            return [];
        }

        try {
            /** @var array<int, array<string, mixed>> $decoded */
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Не удалось разобрать результаты дочернего сканирования: '.$exception->getMessage(), 0, $exception);
        }

        return array_map(
            static fn (array $row): DiscoveredPrinterData => DiscoveredPrinterData::fromArray($row),
            $decoded,
        );
    }

    private function shouldScanChunksInProcessPool(): bool
    {
        if (app()->runningUnitTests()) {
            return false;
        }

        return class_exists(Process::class)
            && is_file(base_path('artisan'))
            && Artisan::has('printers:scan-chunk');
    }
}
