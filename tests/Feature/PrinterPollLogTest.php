<?php

namespace Tests\Feature;

use App\Enums\PrinterStatus;
use App\Jobs\PollPrinterJob;
use App\Models\Printer;
use App\Models\PrinterPollLog;
use App\Services\Notifications\TelegramBotService;
use App\Services\Printers\Data\DiscoveredPrinterData;
use App\Services\Printers\Data\SnmpDiscoveryResult;
use App\Services\Printers\PrinterAlertService;
use App\Services\Printers\PrinterPollingService;
use App\Services\Printers\PrinterSnmpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
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
            public function discoverWithDump(
                string $ipAddress,
                ?string $community = null,
                ?int $timeoutMs = null,
                ?array $probe = null,
            ): SnmpDiscoveryResult {
                return new SnmpDiscoveryResult(
                    discovered: new DiscoveredPrinterData(
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
                    ),
                    dump: [
                        'ip_address' => $ipAddress,
                        'gets' => ['1.3.6.1.2.1.1.1.0' => ['value' => 'printer', 'success' => true]],
                        'walks' => [],
                    ],
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
        $this->assertNotNull($log->raw_snmp_dump);
        $this->assertNotNull($log->normalized_payload);
        $this->assertArrayHasKey('discovered', $log->normalized_payload);
    }

    public function test_scheduled_poll_job_writes_offline_log_with_payload(): void
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
            public function discoverWithDump(
                string $ipAddress,
                ?string $community = null,
                ?int $timeoutMs = null,
                ?array $probe = null,
            ): SnmpDiscoveryResult {
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
        $this->assertSame(RuntimeException::class, $log->exception_class);
        $this->assertNotNull($log->normalized_payload);
        $this->assertNull($log->normalized_payload['discovered']);
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
            public function discoverWithDump(
                string $ipAddress,
                ?string $community = null,
                ?int $timeoutMs = null,
                ?array $probe = null,
            ): SnmpDiscoveryResult {
                return new SnmpDiscoveryResult(
                    discovered: new DiscoveredPrinterData(
                        ipAddress: $ipAddress,
                        discoveredName: 'Kyocera ECOSYS',
                        tonerSupplies: [],
                    ),
                    dump: ['ip_address' => $ipAddress, 'gets' => [], 'walks' => []],
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
        $this->assertIsInt($staleLog->duration_ms);
        $this->assertNotNull($latestLog);
        $this->assertNotSame($staleLog->id, $latestLog->id);
        $this->assertSame('success', $latestLog->status);
    }

    public function test_stale_is_polling_flag_is_cleared_when_no_running_log_exists(): void
    {
        $this->fakeTelegram();

        $printer = Printer::query()->create([
            'name' => 'Kyocera',
            'ip_address' => '192.168.1.63',
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Online,
            'is_active' => true,
            'is_polling' => true,
            'manual_poll_requested_at' => now()->subMinutes(10),
        ]);

        $snmpService = new class extends PrinterSnmpService
        {
            public function discoverWithDump(
                string $ipAddress,
                ?string $community = null,
                ?int $timeoutMs = null,
                ?array $probe = null,
            ): SnmpDiscoveryResult {
                return new SnmpDiscoveryResult(
                    discovered: new DiscoveredPrinterData(
                        ipAddress: $ipAddress,
                        discoveredName: 'Kyocera ECOSYS',
                        tonerSupplies: [],
                    ),
                    dump: ['ip_address' => $ipAddress, 'gets' => [], 'walks' => []],
                );
            }
        };

        $service = new PrinterPollingService(
            $snmpService,
            new PrinterAlertService(new TelegramBotService()),
        );

        (new PollPrinterJob($printer->id, 'scheduled'))->handle($service);

        $printer->refresh();

        $this->assertFalse($printer->is_polling);
        $this->assertNull($printer->manual_poll_requested_at);
    }

    public function test_printers_poll_command_dispatches_jobs_for_active_printers(): void
    {
        Bus::fake();

        Printer::query()->create([
            'name' => 'Active',
            'ip_address' => '192.168.1.70',
            'status' => PrinterStatus::Online,
            'is_active' => true,
        ]);

        Printer::query()->create([
            'name' => 'Inactive',
            'ip_address' => '192.168.1.71',
            'status' => PrinterStatus::Online,
            'is_active' => false,
        ]);

        $this->artisan('printers:poll')->assertSuccessful();

        Bus::assertDispatched(PollPrinterJob::class, 1);
    }

    public function test_poll_log_view_page_renders_with_full_payload_and_fractional_duration(): void
    {
        $dumpPath = base_path('storage/app/printer-poll-dumps/2026-06-11/192.168.1.90.json');

        if (! is_file($dumpPath)) {
            $this->markTestSkipped('SNMP dump fixture is missing.');
        }

        /** @var array<string, mixed> $fixture */
        $fixture = json_decode((string) file_get_contents($dumpPath), true, flags: JSON_THROW_ON_ERROR);

        $printer = Printer::query()->create([
            'name' => 'Kyocera',
            'ip_address' => '192.168.1.90',
            'status' => PrinterStatus::Online,
            'is_active' => true,
        ]);

        $log = PrinterPollLog::query()->create([
            'printer_id' => $printer->id,
            'source' => 'manual',
            'status' => 'success',
            'printer_name' => $printer->display_name,
            'printer_ip' => $printer->ip_address,
            'printer_status' => 'online',
            'raw_snmp_dump' => $fixture['raw_snmp_dump'] ?? null,
            'normalized_payload' => $fixture['normalized_payload'] ?? null,
            'is_partial_response' => (bool) ($fixture['is_partial_response'] ?? false),
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => (int) round((float) ($fixture['duration_ms'] ?? 0)),
        ]);

        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)
            ->get("/admin/printer-poll-logs/{$log->id}")
            ->assertSuccessful();
    }

    public function test_poll_log_view_page_renders_without_payload(): void
    {
        $printer = Printer::query()->create([
            'name' => 'Kyocera',
            'ip_address' => '192.168.1.64',
            'status' => PrinterStatus::Online,
            'is_active' => true,
        ]);

        $log = PrinterPollLog::query()->create([
            'printer_id' => $printer->id,
            'source' => 'scheduled',
            'status' => 'success',
            'printer_name' => $printer->display_name,
            'printer_ip' => $printer->ip_address,
            'printer_status' => 'online',
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 100,
        ]);

        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)
            ->get("/admin/printer-poll-logs/{$log->id}")
            ->assertSuccessful();
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
