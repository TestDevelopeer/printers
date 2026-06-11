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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PrinterAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_it_sends_telegram_notifications_only_when_low_toner_state_changes(): void
    {
        $this->fakeTelegram();

        $printer = $this->makePrinter('192.168.1.25');
        $service = $this->makeService();

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

    public function test_it_deduplicates_repeated_low_toner_notifications_for_same_supply(): void
    {
        $this->fakeTelegram();

        $printer = $this->makePrinter('192.168.1.26');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, new DiscoveredPrinterData(
            ipAddress: '192.168.1.26',
            tonerSupplies: [[
                'slot_key' => '1',
                'color' => 'black',
                'snmp_description' => 'TK-5240K',
                'level' => 9,
                'max_capacity' => 100,
                'percentage' => 9,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '1',
                    'description' => 'TK-5240K',
                ],
            ]],
        ));

        $printer = $printer->fresh(['tonerSupplies']);
        app(PrinterAlertService::class)->dispatchAlerts($printer, $printer->status, []);

        Http::assertSentCount(1);
    }

    public function test_it_notifies_when_printer_goes_offline_and_does_not_repeat_same_status(): void
    {
        $this->fakeTelegram();

        $printer = $this->makePrinter('192.168.1.25', PrinterStatus::Online);

        $snmpService = new class extends PrinterSnmpService
        {
            public function discoverWithDump(
                string $ipAddress,
                ?string $community = null,
                ?int $timeoutMs = null,
                ?array $probe = null,
            ): \App\Services\Printers\Data\SnmpDiscoveryResult {
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
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Изменение статуса принтера'));
    }

    public function test_it_notifies_when_active_cartridge_is_replaced(): void
    {
        $this->fakeTelegram();

        $printer = $this->makePrinter('192.168.1.25');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, new DiscoveredPrinterData(
            ipAddress: '192.168.1.25',
            tonerSupplies: [[
                'slot_key' => '1',
                'color' => 'yellow',
                'snmp_description' => 'TK-5240Y',
                'level' => 70,
                'max_capacity' => 100,
                'percentage' => 70,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '1',
                    'description' => 'TK-5240Y',
                ],
            ]],
        ));

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $service->syncFromDiscovery($printer->fresh(), new DiscoveredPrinterData(
            ipAddress: '192.168.1.25',
            tonerSupplies: [[
                'slot_key' => '1',
                'color' => 'cyan',
                'snmp_description' => 'TK-5240C',
                'level' => 85,
                'max_capacity' => 100,
                'percentage' => 85,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '1',
                    'description' => 'TK-5240C',
                ],
            ]],
        ));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Заменен картридж'));
    }

    public function test_it_does_not_notify_when_identical_active_cartridges_exist_on_different_printers(): void
    {
        $this->fakeTelegram();

        $printerA = $this->makePrinter('192.168.1.30', PrinterStatus::Unknown, 'Printer A');
        $printerB = $this->makePrinter('192.168.1.31', PrinterStatus::Unknown, 'Printer B');
        $service = $this->makeService();

        $service->syncFromDiscovery($printerA, new DiscoveredPrinterData(
            ipAddress: '192.168.1.30',
            tonerSupplies: [[
                'slot_key' => '1',
                'color' => 'black',
                'snmp_description' => 'TK-5240K',
                'level' => 60,
                'max_capacity' => 100,
                'percentage' => 60,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '1',
                    'description' => 'TK-5240K',
                ],
            ]],
        ));

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $service->syncFromDiscovery($printerB, new DiscoveredPrinterData(
            ipAddress: '192.168.1.31',
            tonerSupplies: [[
                'slot_key' => '2',
                'color' => 'black',
                'snmp_description' => 'TK-5240K',
                'level' => 58,
                'max_capacity' => 100,
                'percentage' => 58,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '2',
                    'description' => 'TK-5240K',
                ],
            ]],
        ));

        Http::assertNothingSent();
    }

    public function test_it_does_not_notify_when_history_on_another_printer_has_same_model(): void
    {
        $this->fakeTelegram();

        $printerA = $this->makePrinter('192.168.1.40', PrinterStatus::Unknown, 'Printer A');
        $printerB = $this->makePrinter('192.168.1.41', PrinterStatus::Unknown, 'Printer B');
        $service = $this->makeService();

        $service->syncFromDiscovery($printerA, new DiscoveredPrinterData(
            ipAddress: '192.168.1.40',
            tonerSupplies: [[
                'slot_key' => '1',
                'color' => 'cyan',
                'snmp_description' => 'TK-5240C',
                'level' => 62,
                'max_capacity' => 100,
                'percentage' => 62,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '1',
                    'description' => 'TK-5240C',
                ],
            ]],
        ));

        $service->syncFromDiscovery($printerA->fresh(), new DiscoveredPrinterData(
            ipAddress: '192.168.1.40',
            tonerSupplies: [],
        ));

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $service->syncFromDiscovery($printerB, new DiscoveredPrinterData(
            ipAddress: '192.168.1.41',
            tonerSupplies: [[
                'slot_key' => '3',
                'color' => 'cyan',
                'snmp_description' => 'TK-5240C',
                'level' => 61,
                'max_capacity' => 100,
                'percentage' => 61,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '3',
                    'description' => 'TK-5240C',
                ],
            ]],
        ));

        Http::assertNothingSent();

        return;

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Обнаружен картридж от другого принтера'));
        Http::assertSent(fn ($request) => str_contains($request['text'], 'требуется подтверждение переноса'));
    }

    public function test_it_notifies_when_pending_transfer_is_confirmed(): void
    {
        $this->fakeTelegram();

        $printerA = $this->makePrinter('192.168.1.60', PrinterStatus::Unknown, 'Printer A');
        $printerB = $this->makePrinter('192.168.1.61', PrinterStatus::Unknown, 'Printer B');
        $service = $this->makeService();

        $service->syncFromDiscovery($printerA, new DiscoveredPrinterData(
            ipAddress: '192.168.1.60',
            tonerSupplies: [[
                'slot_key' => '1',
                'color' => 'cyan',
                'snmp_description' => 'TK-5240C',
                'level' => 62,
                'max_capacity' => 100,
                'percentage' => 62,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '1',
                    'description' => 'TK-5240C',
                ],
            ]],
        ));

        $service->syncFromDiscovery($printerA->fresh(), new DiscoveredPrinterData(
            ipAddress: '192.168.1.60',
            tonerSupplies: [],
        ));

        $pendingSupply = $printerA->fresh()->allTonerSupplies()->first();
        $this->assertNotNull($pendingSupply);
        $pendingSupply->forceFill([
            'transfer_target_printer_id' => $printerB->id,
            'transfer_detected_at' => now(),
        ])->save();

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $service->confirmPendingTransfer($pendingSupply);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Перенос картриджа подтвержден'));
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Перенесен из: Printer A'));
    }

    public function test_it_silently_clears_pending_transfer_when_supply_returns_back(): void
    {
        $this->fakeTelegram();

        $printerA = $this->makePrinter('192.168.1.70', PrinterStatus::Unknown, 'Printer A');
        $printerB = $this->makePrinter('192.168.1.71', PrinterStatus::Unknown, 'Printer B');
        $service = $this->makeService();

        $service->syncFromDiscovery($printerA, new DiscoveredPrinterData(
            ipAddress: '192.168.1.70',
            tonerSupplies: [[
                'slot_key' => '1',
                'color' => 'magenta',
                'snmp_description' => 'TK-5240M',
                'level' => 62,
                'max_capacity' => 100,
                'percentage' => 62,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '1',
                    'description' => 'TK-5240M',
                ],
            ]],
        ));

        $service->syncFromDiscovery($printerA->fresh(), new DiscoveredPrinterData(
            ipAddress: '192.168.1.70',
            tonerSupplies: [],
        ));

        $supply = $printerA->fresh()->allTonerSupplies()->first();
        $this->assertNotNull($supply);
        $supply->forceFill([
            'transfer_target_printer_id' => $printerB->id,
            'transfer_detected_at' => now(),
        ])->save();

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $service->syncFromDiscovery($printerB, new DiscoveredPrinterData(
            ipAddress: '192.168.1.71',
            tonerSupplies: [[
                'slot_key' => '2',
                'color' => 'magenta',
                'snmp_description' => 'TK-5240M',
                'level' => 60,
                'max_capacity' => 100,
                'percentage' => 60,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '2',
                    'description' => 'TK-5240M',
                ],
            ]],
        ));

        Http::assertNothingSent();
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

    private function makeService(): PrinterPollingService
    {
        return new PrinterPollingService(
            new PrinterSnmpService(),
            new PrinterAlertService(new TelegramBotService()),
        );
    }

    private function makePrinter(
        string $ipAddress,
        PrinterStatus $status = PrinterStatus::Unknown,
        string $name = 'Kyocera',
    ): Printer {
        return Printer::query()->create([
            'name' => $name,
            'ip_address' => $ipAddress,
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => $status,
            'is_active' => true,
        ]);
    }
}
