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
use Tests\TestCase;

class PrinterPollingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_moves_removed_supplies_to_history_and_reactivates_them(): void
    {
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

        $firstPoll = new DiscoveredPrinterData(
            ipAddress: '192.168.1.25',
            discoveredName: 'Kyocera ECOSYS',
            tonerSupplies: [[
                'slot_key' => '1',
                'color' => 'cyan',
                'snmp_description' => 'TK-5240C',
                'level' => 80,
                'max_capacity' => 100,
                'percentage' => 80,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '1',
                    'description' => 'TK-5240C',
                ],
            ]],
        );

        $service->syncFromDiscovery($printer, $firstPoll);

        $printer->refresh();
        $activeSupply = $printer->tonerSupplies()->first();

        $this->assertNotNull($activeSupply);
        $this->assertSame('TK-5240C', $activeSupply->snmp_description);
        $this->assertNull($activeSupply->removed_at);
        $this->assertCount(0, $printer->tonerHistory()->get());

        $activeSupply->update([
            'comment' => 'Проверен и промаркирован.',
            'is_on_service' => true,
        ]);

        $service->syncFromDiscovery($printer, new DiscoveredPrinterData(
            ipAddress: '192.168.1.25',
            discoveredName: 'Kyocera ECOSYS',
            tonerSupplies: [],
        ));

        $printer->refresh();
        $historySupply = $printer->tonerHistory()->first();

        $this->assertCount(0, $printer->tonerSupplies()->get());
        $this->assertNotNull($historySupply);
        $this->assertSame($activeSupply->id, $historySupply->id);
        $this->assertNotNull($historySupply->removed_at);
        $this->assertSame('Проверен и промаркирован.', $historySupply->comment);
        $this->assertTrue($historySupply->is_on_service);

        $service->syncFromDiscovery($printer, new DiscoveredPrinterData(
            ipAddress: '192.168.1.25',
            discoveredName: 'Kyocera ECOSYS',
            tonerSupplies: [[
                'slot_key' => '1',
                'color' => 'cyan',
                'snmp_description' => 'TK-5240C',
                'level' => 55,
                'max_capacity' => 100,
                'percentage' => 55,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '1',
                    'description' => 'TK-5240C',
                ],
            ]],
        ));

        $printer->refresh();
        $reactivatedSupply = $printer->tonerSupplies()->first();

        $this->assertNotNull($reactivatedSupply);
        $this->assertSame($activeSupply->id, $reactivatedSupply->id);
        $this->assertSame(55, $reactivatedSupply->percentage);
        $this->assertNull($reactivatedSupply->removed_at);
        $this->assertCount(0, $printer->tonerHistory()->get());
        $this->assertSame('Проверен и промаркирован.', $reactivatedSupply->comment);
        $this->assertTrue($reactivatedSupply->is_on_service);
    }
}
