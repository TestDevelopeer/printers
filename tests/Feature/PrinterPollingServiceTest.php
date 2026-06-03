<?php

namespace Tests\Feature;

use App\Enums\PrinterStatus;
use App\Models\Printer;
use App\Models\TonerSupply;
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
        $printer = $this->makePrinter('192.168.1.25');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, new DiscoveredPrinterData(
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
        ));

        $printer->refresh();
        $activeSupply = $printer->tonerSupplies()->first();

        $this->assertNotNull($activeSupply);
        $this->assertSame('TK-5240C', $activeSupply->snmp_description);
        $this->assertNull($activeSupply->removed_at);
        $this->assertCount(0, $printer->tonerHistory()->get());

        $activeSupply->update([
            'color' => 'magenta',
            'is_color_manual' => true,
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
        $this->assertSame('magenta', $historySupply->color?->value);

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
        $this->assertFalse($reactivatedSupply->is_on_service);
        $this->assertSame('magenta', $reactivatedSupply->color?->value);
        $this->assertSame('cyan', $reactivatedSupply->detected_color?->value);
    }

    public function test_it_reuses_the_same_supply_when_only_slot_changes(): void
    {
        $printer = $this->makePrinter('192.168.1.26');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, new DiscoveredPrinterData(
            ipAddress: '192.168.1.26',
            tonerSupplies: [[
                'slot_key' => '1',
                'color' => 'yellow',
                'snmp_description' => 'TK-5240Y',
                'level' => 75,
                'max_capacity' => 100,
                'percentage' => 75,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '1',
                    'description' => 'TK-5240Y',
                ],
            ]],
        ));

        $firstSupply = $printer->fresh()->tonerSupplies()->first();

        $service->syncFromDiscovery($printer->fresh(), new DiscoveredPrinterData(
            ipAddress: '192.168.1.26',
            tonerSupplies: [[
                'slot_key' => '2',
                'color' => 'yellow',
                'snmp_description' => 'TK-5240Y',
                'level' => 70,
                'max_capacity' => 100,
                'percentage' => 70,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '2',
                    'description' => 'TK-5240Y',
                ],
            ]],
        ));

        $updatedSupply = $printer->fresh()->tonerSupplies()->first();

        $this->assertNotNull($firstSupply);
        $this->assertNotNull($updatedSupply);
        $this->assertSame($firstSupply->id, $updatedSupply->id);
        $this->assertSame('2', $updatedSupply->slot_key);
        $this->assertCount(1, TonerSupply::query()->where('supply_signature', $updatedSupply->supply_signature)->get());
    }

    public function test_it_creates_separate_active_supplies_for_identical_printers(): void
    {
        $printerA = $this->makePrinter('192.168.1.30', 'Printer A');
        $printerB = $this->makePrinter('192.168.1.31', 'Printer B');
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

        $service->syncFromDiscovery($printerB, new DiscoveredPrinterData(
            ipAddress: '192.168.1.31',
            tonerSupplies: [[
                'slot_key' => '3',
                'color' => 'black',
                'snmp_description' => 'TK-5240K',
                'level' => 58,
                'max_capacity' => 100,
                'percentage' => 58,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '3',
                    'description' => 'TK-5240K',
                ],
            ]],
        ));

        $printerA->refresh();
        $printerB->refresh();

        $supplyA = $printerA->tonerSupplies()->first();
        $supplyB = $printerB->tonerSupplies()->first();

        $this->assertNotNull($supplyA);
        $this->assertNotNull($supplyB);
        $this->assertNotSame($supplyA->id, $supplyB->id);
        $this->assertSame($supplyA->supply_signature, $supplyB->supply_signature);
        $this->assertFalse($supplyA->needsTransferConfirmation());
        $this->assertFalse($supplyB->needsTransferConfirmation());
        $this->assertCount(1, $printerA->displayed_toner_supplies);
        $this->assertCount(1, $printerB->displayed_toner_supplies);
        $this->assertCount(0, $printerA->incomingPendingTonerSupplies()->get());
        $this->assertCount(0, $printerB->incomingPendingTonerSupplies()->get());
    }

    public function test_it_marks_historical_supply_for_confirmation_and_can_confirm_transfer(): void
    {
        $printerA = $this->makePrinter('192.168.1.40', 'Printer A');
        $printerB = $this->makePrinter('192.168.1.41', 'Printer B');
        $service = $this->makeService();

        $service->syncFromDiscovery($printerA, new DiscoveredPrinterData(
            ipAddress: '192.168.1.40',
            tonerSupplies: [[
                'slot_key' => '1',
                'color' => 'cyan',
                'snmp_description' => 'TK-5240C',
                'level' => 66,
                'max_capacity' => 100,
                'percentage' => 66,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '1',
                    'description' => 'TK-5240C',
                ],
            ]],
        ));

        $supply = $printerA->fresh()->tonerSupplies()->first();
        $this->assertNotNull($supply);

        $service->syncFromDiscovery($printerA->fresh(), new DiscoveredPrinterData(
            ipAddress: '192.168.1.40',
            tonerSupplies: [],
        ));

        $this->assertCount(1, $printerA->fresh()->tonerHistory()->get());

        $service->syncFromDiscovery($printerB, new DiscoveredPrinterData(
            ipAddress: '192.168.1.41',
            tonerSupplies: [[
                'slot_key' => '2',
                'color' => 'cyan',
                'snmp_description' => 'TK-5240C',
                'level' => 64,
                'max_capacity' => 100,
                'percentage' => 64,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '2',
                    'description' => 'TK-5240C',
                ],
            ]],
        ));

        $pendingSupply = $supply->fresh(['printer', 'transferTargetPrinter']);

        $this->assertTrue($pendingSupply->needsTransferConfirmation());
        $this->assertSame($printerA->id, $pendingSupply->printer_id);
        $this->assertSame($printerB->id, $pendingSupply->transfer_target_printer_id);
        $this->assertCount(1, $printerB->fresh()->displayed_toner_supplies);

        $confirmedSupply = $service->confirmPendingTransfer($pendingSupply);

        $this->assertSame($printerB->id, $confirmedSupply->printer_id);
        $this->assertNull($confirmedSupply->transfer_target_printer_id);
        $this->assertNull($confirmedSupply->removed_at);
        $this->assertFalse($confirmedSupply->needsTransferConfirmation());
        $this->assertCount(0, $printerA->fresh()->tonerSupplies()->get());
        $this->assertCount(1, $printerB->fresh()->tonerSupplies()->get());
    }

    public function test_it_clears_pending_transfer_when_supply_disappears_from_target_printer(): void
    {
        $printerA = $this->makePrinter('192.168.1.50', 'Printer A');
        $printerB = $this->makePrinter('192.168.1.51', 'Printer B');
        $service = $this->makeService();

        $service->syncFromDiscovery($printerA, new DiscoveredPrinterData(
            ipAddress: '192.168.1.50',
            tonerSupplies: [[
                'slot_key' => '1',
                'color' => 'cyan',
                'snmp_description' => 'TK-5240C',
                'level' => 66,
                'max_capacity' => 100,
                'percentage' => 66,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '1',
                    'description' => 'TK-5240C',
                ],
            ]],
        ));

        $supply = $printerA->fresh()->tonerSupplies()->first();
        $this->assertNotNull($supply);

        $service->syncFromDiscovery($printerA->fresh(), new DiscoveredPrinterData(
            ipAddress: '192.168.1.50',
            tonerSupplies: [],
        ));

        $service->syncFromDiscovery($printerB, new DiscoveredPrinterData(
            ipAddress: '192.168.1.51',
            tonerSupplies: [[
                'slot_key' => '2',
                'color' => 'cyan',
                'snmp_description' => 'TK-5240C',
                'level' => 64,
                'max_capacity' => 100,
                'percentage' => 64,
                'unit' => 'percent',
                'is_known' => true,
                'raw_value' => [
                    'slot_key' => '2',
                    'description' => 'TK-5240C',
                ],
            ]],
        ));

        $this->assertSame($printerB->id, $supply->fresh()->transfer_target_printer_id);

        $service->syncFromDiscovery($printerB->fresh(), new DiscoveredPrinterData(
            ipAddress: '192.168.1.51',
            tonerSupplies: [],
        ));

        $this->assertNull($supply->fresh()->transfer_target_printer_id);
    }

    private function makeService(): PrinterPollingService
    {
        return new PrinterPollingService(
            new PrinterSnmpService(),
            new PrinterAlertService(new TelegramBotService()),
        );
    }

    private function makePrinter(string $ipAddress, string $name = 'Kyocera'): Printer
    {
        return Printer::query()->create([
            'name' => $name,
            'ip_address' => $ipAddress,
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Unknown,
            'is_active' => true,
        ]);
    }
}
