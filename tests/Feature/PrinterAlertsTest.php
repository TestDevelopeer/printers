<?php

namespace Tests\Feature;

use App\Enums\PrinterStatus;
use App\Models\Printer;
use App\Services\Notifications\TelegramBotService;
use App\Services\Printers\Data\DiscoveredPrinterData;
use App\Services\Printers\PrinterAlertService;
use App\Services\Printers\PrinterPollingService;
use App\Services\Printers\PrinterSnmpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PrinterAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_telegram_notifications_only_when_low_toner_state_changes(): void
    {
        config([
            'printers.telegram.bot_token' => 'token',
            'printers.telegram.chat_id' => 'chat',
        ]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $printer = Printer::query()->create([
            'name' => 'Kyocera',
            'ip_address' => '192.168.1.25',
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Unknown,
            'is_active' => true,
        ]);

        $service = new PrinterPollingService(
            new PrinterSnmpService(),
            new PrinterAlertService(new TelegramBotService()),
        );

        $service->syncFromDiscovery($printer, new DiscoveredPrinterData(
            ipAddress: '192.168.1.25',
            tonerSupplies: [[
                'slot_key' => '1',
                'color' => 'black',
                'snmp_description' => 'TK-5240K',
                'level' => 10,
                'max_capacity' => 100,
                'percentage' => 10,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '1',
                    'description' => 'TK-5240K',
                ],
            ]],
        ));

        $service->syncFromDiscovery($printer->fresh(), new DiscoveredPrinterData(
            ipAddress: '192.168.1.25',
            tonerSupplies: [[
                'slot_key' => '1',
                'color' => 'black',
                'snmp_description' => 'TK-5240K',
                'level' => 8,
                'max_capacity' => 100,
                'percentage' => 8,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '1',
                    'description' => 'TK-5240K',
                ],
            ]],
        ));

        $service->syncFromDiscovery($printer->fresh(), new DiscoveredPrinterData(
            ipAddress: '192.168.1.25',
            tonerSupplies: [[
                'slot_key' => '1',
                'color' => 'black',
                'snmp_description' => 'TK-5240K',
                'level' => 65,
                'max_capacity' => 100,
                'percentage' => 65,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '1',
                    'description' => 'TK-5240K',
                ],
            ]],
        ));

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Низкий уровень тонера'));
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Тонер восстановился'));
    }

    public function test_it_notifies_when_printer_goes_offline_and_does_not_repeat_same_status(): void
    {
        config([
            'printers.telegram.bot_token' => 'token',
            'printers.telegram.chat_id' => 'chat',
        ]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $printer = Printer::query()->create([
            'name' => 'Kyocera',
            'ip_address' => '192.168.1.25',
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

        $service->poll($printer);
        $service->poll($printer->fresh());

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'сменил статус'));
    }
}
