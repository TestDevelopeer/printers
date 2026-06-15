<?php

namespace Tests\Feature;

use App\Enums\PrinterStatus;
use App\Models\Printer;
use App\Models\TonerSupply;
use App\Services\Printers\Data\DiscoveredPrinterData;
use App\Services\Printers\PrinterPollingService;
use App\Services\Printers\PrinterSnmpService;
use App\Services\Printers\TonerSupplyIdentityService;
use App\Services\Notifications\TelegramBotService;
use App\Services\Printers\PrinterAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TonerSupplyServiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_active_to_service_moves_supply_to_history_and_marks_slot_awaiting_poll(): void
    {
        $printer = $this->makePrinter('192.168.1.40');
        $supply = $this->createActiveSupply($printer, '2', 55);

        app(TonerSupplyIdentityService::class)->sendActiveToService(
            $supply,
            'magenta',
            'Старый картридж',
        );

        $printer->refresh();
        $supply->refresh();

        $this->assertNotNull($supply->removed_at);
        $this->assertSame('2', $supply->history_slot_key);
        $this->assertTrue($supply->is_on_service);
        $this->assertSame('Старый картридж', $supply->comment);
        $this->assertCount(0, $printer->tonerSupplies);
        $this->assertSame(['2'], $printer->awaiting_slot_poll_keys);

        $items = $printer->ordered_toner_display_items;
        $this->assertCount(1, $items);
        $this->assertSame('placeholder', $items[0]['type']);
        $this->assertSame('2', $items[0]['slot_key']);
    }

    public function test_poll_clears_awaiting_slot_when_snmp_returns_supply_for_slot(): void
    {
        $printer = $this->makePrinter('192.168.1.41');
        $supply = $this->createActiveSupply($printer, '1', 40);

        app(TonerSupplyIdentityService::class)->sendActiveToService($supply, 'black', 'На заправку');

        $service = $this->makePollingService();

        $service->syncFromDiscovery($printer->fresh(), $this->discovery([
            $this->unknownSupply('1', 'black', 'Non-OEM'),
        ]));

        $printer->refresh();

        $this->assertSame([], $printer->awaiting_slot_poll_keys ?? []);
        $this->assertCount(1, $printer->tonerSupplies);
        $this->assertSame('Non-OEM', $printer->tonerSupplies->first()?->snmp_description);
        $this->assertFalse($printer->tonerSupplies->first()?->is_known);
    }

    public function test_ordered_display_items_keep_slot_order_between_active_and_placeholder(): void
    {
        $printer = $this->makePrinter('192.168.1.42');
        $this->createActiveSupply($printer, '1', 80);
        $slotTwo = $this->createActiveSupply($printer, '3', 70);

        app(TonerSupplyIdentityService::class)->sendActiveToService($slotTwo, 'yellow', null);

        $printer->refresh();

        $slotKeys = array_map(
            static fn (array $item): string => $item['slot_key'],
            $printer->ordered_toner_display_items,
        );

        $this->assertSame(['1', '3'], $slotKeys);
        $this->assertSame('supply', $printer->ordered_toner_display_items[0]['type']);
        $this->assertSame('placeholder', $printer->ordered_toner_display_items[1]['type']);
    }

    public function test_activate_from_history_installs_cartridge_into_awaiting_slot(): void
    {
        $printer = $this->makePrinter('192.168.1.43');
        $supply = $this->createActiveSupply($printer, '2', 55);

        $service = app(TonerSupplyIdentityService::class);
        $service->sendActiveToService($supply, 'magenta', 'На заправку');

        $printer->refresh();
        $supply->refresh();

        $activated = $service->activateFromHistory($printer, '2', $supply);

        $printer->refresh();
        $supply->refresh();

        $this->assertNull($activated->removed_at);
        $this->assertSame('2', $activated->slot_key);
        $this->assertFalse($activated->is_on_service);
        $this->assertSame([], $printer->awaiting_slot_poll_keys ?? []);
        $this->assertCount(1, $printer->tonerSupplies);
        $this->assertTrue($printer->tonerSupplies->first()?->is($activated));
    }

    public function test_activate_from_history_swaps_with_current_active_supply(): void
    {
        $printer = $this->makePrinter('192.168.1.44');
        $historical = $this->createActiveSupply($printer, '2', 55);

        $service = app(TonerSupplyIdentityService::class);
        $service->sendActiveToService($historical, 'magenta', 'Старый');

        $currentActive = $this->createActiveSupply($printer, '2', 90);
        $printer->addAwaitingSlotPollKey('2');
        $printer->save();

        $activated = $service->activateFromHistory($printer, '2', $historical->fresh());

        $printer->refresh();
        $historical->refresh();
        $currentActive->refresh();

        $this->assertNull($activated->removed_at);
        $this->assertSame('2', $activated->slot_key);
        $this->assertFalse($activated->is_on_service);

        $this->assertNotNull($currentActive->removed_at);
        $this->assertSame('2', $currentActive->history_slot_key);
        $this->assertFalse($currentActive->is_on_service);
        $this->assertSame([], $printer->awaiting_slot_poll_keys ?? []);
        $this->assertCount(1, $printer->tonerSupplies);
        $this->assertTrue($printer->tonerSupplies->first()?->is($activated));
    }

    public function test_activate_from_history_restores_service_cartridge_with_metadata(): void
    {
        $printer = $this->makePrinter('192.168.1.45');
        $supply = $this->createActiveSupply($printer, '1', 40);

        $service = app(TonerSupplyIdentityService::class);
        $service->sendActiveToService($supply, 'black', 'На обслуживание');

        $supply->refresh();

        $activated = $service->activateFromHistory(
            $printer,
            '1',
            $supply,
            'cyan',
            'Вернулся с заправки',
        );

        $supply->refresh();

        $this->assertNull($activated->removed_at);
        $this->assertSame('1', $activated->slot_key);
        $this->assertFalse($activated->is_on_service);
        $this->assertSame('cyan', $activated->color?->value ?? $activated->color);
        $this->assertTrue($activated->is_color_manual);
        $this->assertSame('Вернулся с заправки', $activated->comment);
    }

    private function makePrinter(string $ipAddress): Printer
    {
        return Printer::query()->create([
            'name' => 'Kyocera',
            'ip_address' => $ipAddress,
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Online,
            'is_active' => true,
        ]);
    }

    private function createActiveSupply(Printer $printer, string $slot, int $percentage): TonerSupply
    {
        return TonerSupply::query()->create([
            'printer_id' => $printer->id,
            'slot_key' => $slot,
            'color' => 'black',
            'detected_color' => 'black',
            'snmp_description' => "TK-{$slot}",
            'level' => $percentage,
            'max_capacity' => 100,
            'percentage' => $percentage,
            'unit' => 'percent',
            'is_known' => true,
            'installed_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $supplies
     */
    private function discovery(array $supplies): DiscoveredPrinterData
    {
        return new DiscoveredPrinterData(
            ipAddress: '192.168.1.41',
            discoveredName: 'Kyocera',
            tonerSupplies: $supplies,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function unknownSupply(string $slot, string $color, string $description): array
    {
        return [
            'slot_key' => $slot,
            'color' => $color,
            'snmp_description' => $description,
            'level' => -3,
            'max_capacity' => -2,
            'percentage' => null,
            'unit' => 'percent',
            'is_known' => false,
            'raw_value' => [
                'slot_key' => $slot,
                'description' => $description,
            ],
        ];
    }

    private function makePollingService(): PrinterPollingService
    {
        return new PrinterPollingService(
            new PrinterSnmpService(),
            new PrinterAlertService(new TelegramBotService()),
        );
    }
}
