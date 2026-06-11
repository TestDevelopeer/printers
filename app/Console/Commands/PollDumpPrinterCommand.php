<?php

namespace App\Console\Commands;

use App\Jobs\PollPrinterJob;
use App\Models\Printer;
use App\Models\PrinterPollLog;
use App\Services\Printers\PrinterPollingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PollDumpPrinterCommand extends Command
{
    protected $signature = 'printers:poll-dump {ip : IP-адрес принтера}';

    protected $description = 'Опросить принтер по IP и сохранить полный JSON-дамп в файл.';

    public function handle(PrinterPollingService $printerPollingService): int
    {
        $ip = (string) $this->argument('ip');
        $printer = Printer::query()->where('ip_address', $ip)->first();

        if ($printer === null) {
            $this->error("Принтер с IP {$ip} не найден в базе данных.");

            return self::FAILURE;
        }

        (new PollPrinterJob($printer->id, 'cli'))->handle($printerPollingService);

        $log = PrinterPollLog::query()
            ->where('printer_id', $printer->id)
            ->latest('id')
            ->first();

        $payload = [
            'ip_address' => $ip,
            'printer_id' => $printer->id,
            'polled_at' => now()->toIso8601String(),
            'log_id' => $log?->id,
            'source' => $log?->source,
            'status' => $log?->status,
            'printer_status' => $log?->printer_status,
            'message' => $log?->message,
            'raw_snmp_dump' => $log?->raw_snmp_dump,
            'normalized_payload' => $log?->normalized_payload,
            'exception_class' => $log?->exception_class,
            'is_partial_response' => $log?->is_partial_response,
            'started_at' => $log?->started_at?->toIso8601String(),
            'finished_at' => $log?->finished_at?->toIso8601String(),
            'duration_ms' => $log?->duration_ms,
        ];

        $path = $this->saveDumpFile($ip, $payload);

        $this->info("Дамп сохранён: {$path}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function saveDumpFile(string $ip, array $payload): string
    {
        $directory = storage_path('app/printer-poll-dumps/'.now()->format('Y-m-d'));
        File::ensureDirectoryExists($directory);

        $path = $directory.'/'.$ip.'.json';
        File::put(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        );

        return $path;
    }
}
