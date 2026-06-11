<?php

namespace App\Console\Commands;

use App\Services\Printers\NetworkScannerService;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

class ScanPrinterHostsChunkCommand extends Command
{
    protected $signature = 'printers:scan-chunk';

    protected $description = 'Внутренняя команда: SNMP-сканирование списка хостов из stdin JSON';

    public function handle(NetworkScannerService $networkScannerService): int
    {
        $input = stream_get_contents(STDIN);

        if ($input === false || trim($input) === '') {
            throw new RuntimeException('Не переданы данные для сканирования.');
        }

        try {
            /** @var array{hosts: array<int, string>, community: string, timeout: int} $payload */
            $payload = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Некорректный JSON для сканирования: '.$exception->getMessage(), 0, $exception);
        }

        $results = $networkScannerService->scanHosts(
            $payload['hosts'] ?? [],
            $payload['community'] ?? config('printers.default_snmp_community', 'public'),
            (int) ($payload['timeout'] ?? config('printers.scan_timeout', 1000)),
        );

        $encoded = json_encode(
            array_map(static fn ($printer) => $printer->toArray(), $results),
            JSON_THROW_ON_ERROR,
        );

        $this->output->write($encoded);

        return self::SUCCESS;
    }
}
