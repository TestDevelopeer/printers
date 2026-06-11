<?php

namespace Tests\Feature;

use App\Enums\PrinterStatus;
use App\Jobs\PollPrinterJob;
use App\Models\Printer;
use App\Models\PrinterPollLog;
use App\Services\Notifications\TelegramBotService;
use App\Services\Printers\Data\DiscoveredPrinterData;
use App\Services\Printers\PrinterAlertService;
use App\Services\Printers\PrinterPollingService;
use App\Services\Printers\PrinterSnmpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PrinterPollLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_poll_job_clears_manual_poll_marker_and_writes_log(): void
    {
        $this->fakeTelegram();

        $printer = Printer::query()->create([
            'name' => 'Kyocera',
            'ip_address' => '192.168.1.60',
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Unknown,
            'is_active' => true,
            'is_polling' => true,
            'manual_poll_requested_at' => now(),
        ]);

        $snmpService = new class extends PrinterSnmpService
        {
            public function discover(
                string $ipAddress,
                ?string $community = null,
                ?int $timeoutMs = null,
                ?array $probe = null,
            ): ?DiscoveredPrinterData {
                return new DiscoveredPrinterData(
                    ipAddress: $ipAddress,
                    discoveredName: 'Kyocera ECOSYS',
                    tonerSupplies: [[
                        'slot_key' => '1',
                        'color' => 'black',
                        'snmp_description' => 'TK-5240K',
                        'level' => 70,
                        'max_capacity' => 100,
                        'percentage' => 70,
                        'unit' => 'percent',
                        'is_known' => true,
                        'raw_value' => [
                            'slot_key' => '1',
                            'description' => 'TK-5240K',
                        ],
                    ]],
                );
            }
        };

        $service = new PrinterPollingService(
            $snmpService,
            new PrinterAlertService(new TelegramBotService()),
        );

        (new PollPrinterJob($printer->id, 'manual'))->handle($service);

        $printer->refresh();
        $log = PrinterPollLog::query()->latest('id')->first();

        $this->assertFalse($printer->is_polling);
        $this->assertNull($printer->manual_poll_requested_at);
        $this->assertNotNull($log);
        $this->assertSame('manual', $log->source);
        $this->assertSame('success', $log->status);
        $this->assertSame('online', $log->printer_status);
        $this->assertNotNull($log->finished_at);
    }

    public function test_scheduled_poll_job_writes_offline_log(): void
    {
        $this->fakeTelegram();

        $printer = Printer::query()->create([
            'name' => 'Kyocera',
            'ip_address' => '192.168.1.61',
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Online,
            'is_active' => true,
        ]);

        $snmpService = new class extends PrinterSnmpService
        {
            public function discover(
                string $ipAddress,
                ?string $community = null,
                ?int $timeoutMs = null,
                ?array $probe = null,
            ): ?DiscoveredPrinterData {
                throw new RuntimeException('timeout');
            }
        };

        $service = new PrinterPollingService(
            $snmpService,
            new PrinterAlertService(new TelegramBotService()),
        );

        (new PollPrinterJob($printer->id, 'scheduled'))->handle($service);

        $log = PrinterPollLog::query()->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('scheduled', $log->source);
        $this->assertSame('offline', $log->status);
        $this->assertSame('offline', $log->printer_status);
        $this->assertNotNull($log->finished_at);
    }

    public function test_new_poll_closes_previous_running_log_for_same_printer(): void
    {
        $this->fakeTelegram();

        $printer = Printer::query()->create([
            'name' => 'Kyocera',
            'ip_address' => '192.168.1.62',
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Unknown,
            'is_active' => true,
            'is_polling' => true,
            'manual_poll_requested_at' => now()->subMinutes(10),
        ]);

        $staleLog = PrinterPollLog::query()->create([
            'printer_id' => $printer->id,
            'source' => 'manual',
            'status' => 'running',
            'printer_name' => $printer->display_name,
            'printer_ip' => $printer->ip_address,
            'started_at' => Carbon::now()->subMinutes(10),
        ]);

        $snmpService = new class extends PrinterSnmpService
        {
            public function discover(
                string $ipAddress,
                ?string $community = null,
                ?int $timeoutMs = null,
                ?array $probe = null,
            ): ?DiscoveredPrinterData {
                return new DiscoveredPrinterData(
                    ipAddress: $ipAddress,
                    discoveredName: 'Kyocera ECOSYS',
                    tonerSupplies: [],
                );
            }
        };

        $service = new PrinterPollingService(
            $snmpService,
            new PrinterAlertService(new TelegramBotService()),
        );

        (new PollPrinterJob($printer->id, 'manual'))->handle($service);

        $staleLog->refresh();
        $latestLog = PrinterPollLog::query()->latest('id')->first();

        $this->assertSame('error', $staleLog->status);
        $this->assertSame('Previous poll did not finish cleanly.', $staleLog->message);
        $this->assertNotNull($staleLog->finished_at);
        $this->assertNotNull($latestLog);
        $this->assertNotSame($staleLog->id, $latestLog->id);
        $this->assertSame('success', $latestLog->status);
    }

    private function fakeTelegram(): void
    {
        config([
            'printers.telegram.bot_token' => 'token',
            'printers.telegram.chat_id' => 'chat',
        ]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }
}
