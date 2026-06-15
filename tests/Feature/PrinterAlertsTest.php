<?php

namespace Tests\Feature;

use App\Enums\PrinterStatus;
use App\Models\Printer;
use App\Services\Notifications\TelegramBotService;
use App\Services\Printers\Data\DiscoveredPrinterData;
use App\Services\Printers\Data\SnmpDiscoveryResult;
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

        $service->syncFromDiscovery($printer, $this->discovery([$this->supply('1', 'black', 'TK-5240K', 10)]));
        $service->syncFromDiscovery($printer->fresh(), $this->discovery([$this->supply('1', 'black', 'TK-5240K', 8)]));
        $service->syncFromDiscovery($printer->fresh(), $this->discovery([$this->supply('1', 'black', 'TK-5240K', 7)]));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Низкий уровень тонера'));
        Http::assertSent(fn ($request) => str_contains($request['text'], '🆔 Принтер: #'));
        Http::assertSent(fn ($request) => str_contains($request['text'], '🧩 Слот: 1'));
        Http::assertSent(fn ($request) => str_contains($request['text'], '🆔 Картридж: #'));
    }

    public function test_it_sends_recovered_toner_notification_without_replacement_duplicate(): void
    {
        $this->fakeTelegram();

        $printer = $this->makePrinter('192.168.1.25');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, $this->discovery([$this->supply('1', 'black', 'TK-5240K', 14)]));
        $service->syncFromDiscovery($printer->fresh(), $this->discovery([$this->supply('1', 'black', 'TK-5240K', 16)]));

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Низкий уровень тонера'));
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Тонер восстановился'));
        Http::assertSent(fn ($request) => ! str_contains($request['text'], 'Заменён картридж'));
    }

    public function test_it_deduplicates_repeated_low_toner_notifications_for_same_supply(): void
    {
        $this->fakeTelegram();

        $printer = $this->makePrinter('192.168.1.26');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, $this->discovery([$this->supply('1', 'black', 'TK-5240K', 9)]));

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
            ): SnmpDiscoveryResult {
                throw new RuntimeException('timeout');
            }
        };

        $service = new PrinterPollingService(
            $snmpService,
            new PrinterAlertService(new TelegramBotService),
        );

        $service->poll($printer);
        $service->poll($printer->fresh());

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Изменение статуса принтера'));
    }

    public function test_it_notifies_when_toner_level_increase_indicates_replacement(): void
    {
        $this->fakeTelegram();

        $printer = $this->makePrinter('192.168.1.25');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, $this->discovery([$this->supply('1', 'yellow', 'TK-5240Y', 15)]));

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $service->syncFromDiscovery($printer->fresh(), $this->discovery([$this->supply('1', 'yellow', 'TK-5240Y', 85)]));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Заменён картридж'));
        Http::assertSent(fn ($request) => str_contains($request['text'], 'TK-5240Y'));
        Http::assertSent(fn ($request) => str_contains($request['text'], '🆔 Принтер: #'));
        Http::assertSent(fn ($request) => str_contains($request['text'], '🧩 Слот: 1'));
        Http::assertSent(fn ($request) => str_contains($request['text'], '🆔 Картридж: #'));
        Http::assertSent(fn ($request) => ! str_contains($request['text'], 'Тонер восстановился'));
    }

    public function test_it_does_not_notify_replacement_when_only_description_changes(): void
    {
        $this->fakeTelegram();

        $printer = $this->makePrinter('192.168.1.30');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, $this->discovery([$this->supply('1', 'yellow', 'TK-5240Y', 70)]));

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $service->syncFromDiscovery($printer->fresh(), $this->discovery([[
            'slot_key' => '1',
            'color' => 'cyan',
            'snmp_description' => 'TK-5240C',
            'level' => 71,
            'max_capacity' => 100,
            'percentage' => 71,
            'unit' => 'percent',
            'is_known' => true,
            'raw_value' => [
                'slot_key' => '1',
                'description' => 'TK-5240C',
            ],
        ]]));

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

    /**
     * @param  array<int, array<string, mixed>>  $supplies
     */
    private function discovery(array $supplies): DiscoveredPrinterData
    {
        return new DiscoveredPrinterData(
            ipAddress: '192.168.1.25',
            tonerSupplies: $supplies,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function supply(string $slot, string $color, string $description, int $percentage): array
    {
        return [
            'slot_key' => $slot,
            'color' => $color,
            'snmp_description' => $description,
            'level' => $percentage,
            'max_capacity' => 100,
            'percentage' => $percentage,
            'unit' => 'percent',
            'is_known' => true,
            'raw_value' => [
                'slot_key' => $slot,
                'description' => $description,
            ],
        ];
    }

    private function makeService(): PrinterPollingService
    {
        return new PrinterPollingService(
            new PrinterSnmpService,
            new PrinterAlertService(new TelegramBotService),
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
