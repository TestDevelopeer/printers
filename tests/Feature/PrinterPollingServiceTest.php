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
use App\Services\Printers\TonerSupplyIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrinterPollingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_poll_creates_one_supply_per_slot(): void
    {
        $printer = $this->makePrinter('192.168.1.25');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, $this->discovery([
            $this->supply('1', 'cyan', 'TK-5240C', 80),
            $this->supply('2', 'magenta', 'TK-5240M', 70),
        ]));

        $printer->refresh();

        $this->assertCount(2, $printer->tonerSupplies);
        $this->assertSame(0, TonerSupply::query()->onService()->count());
        $this->assertFalse($printer->tonerSupplies->contains(fn (TonerSupply $s) => $s->needs_identity_confirmation));
    }

    public function test_toner_increase_creates_provisional_supply_and_moves_old_to_service(): void
    {
        $printer = $this->makePrinter('192.168.1.26');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, $this->discovery([
            $this->supply('2', 'magenta', 'TK-5240M', 15),
        ]));

        $oldSupply = $printer->fresh()->tonerSupplies()->first();
        $oldSupply?->update(['comment' => 'Номер 2']);

        $service->syncFromDiscovery($printer->fresh(), $this->discovery([
            $this->supply('2', 'magenta', 'TK-5240M', 80),
        ]));

        $printer->refresh();
        $active = $printer->tonerSupplies()->first();
        $serviceSupply = TonerSupply::query()->onService()->first();

        $this->assertNotNull($active);
        $this->assertNotNull($serviceSupply);
        $this->assertNotSame($oldSupply?->id, $active->id);
        $this->assertSame($oldSupply?->id, $serviceSupply->id);
        $this->assertSame('2', $serviceSupply->history_slot_key);
        $this->assertSame('Номер 2', $serviceSupply->comment);
        $this->assertTrue($active->needs_identity_confirmation);
        $this->assertSame(80, $active->percentage);
    }

    public function test_small_toner_increase_does_not_trigger_replacement(): void
    {
        $printer = $this->makePrinter('192.168.1.27');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, $this->discovery([
            $this->supply('1', 'yellow', 'TK-5240Y', 70),
        ]));

        $supply = $printer->fresh()->tonerSupplies()->first();

        $service->syncFromDiscovery($printer->fresh(), $this->discovery([
            $this->supply('1', 'yellow', 'TK-5240Y', 72),
        ]));

        $printer->refresh();

        $this->assertCount(1, $printer->tonerSupplies);
        $this->assertSame(0, TonerSupply::query()->onService()->count());
        $this->assertSame($supply?->id, $printer->tonerSupplies()->first()?->id);
        $this->assertFalse($printer->tonerSupplies()->first()?->needs_identity_confirmation);
    }

    public function test_unknown_toner_status_does_not_trigger_replacement_when_previous_was_known(): void
    {
        $printer = $this->makePrinter('192.168.1.28');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, $this->discovery([
            $this->supply('1', 'black', 'TK-5240K', 45),
        ]));

        $supply = $printer->fresh()->tonerSupplies()->first();

        $service->syncFromDiscovery($printer->fresh(), $this->discovery([
            $this->unknownSupply('1', 'black', 'TK-5240K'),
        ]));

        $printer->refresh();
        $active = $printer->tonerSupplies()->first();

        $this->assertCount(1, $printer->tonerSupplies);
        $this->assertSame(0, TonerSupply::query()->onService()->count());
        $this->assertSame($supply?->id, $active?->id);
        $this->assertFalse($active?->needs_identity_confirmation);
        $this->assertTrue($active?->is_known);
        $this->assertSame(45, $active?->percentage);
    }

    public function test_unknown_to_unknown_does_not_trigger_replacement(): void
    {
        $printer = $this->makePrinter('192.168.1.28');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, $this->discovery([
            $this->unknownSupply('5', 'waste', 'Waste Toner Box'),
        ]));

        $supply = $printer->fresh()->tonerSupplies()->first();

        $service->syncFromDiscovery($printer->fresh(), $this->discovery([
            $this->unknownSupply('5', 'waste', 'Waste Toner Box'),
        ]));

        $printer->refresh();

        $this->assertCount(1, $printer->tonerSupplies);
        $this->assertSame(0, TonerSupply::query()->onService()->count());
        $this->assertSame($supply?->id, $printer->tonerSupplies()->first()?->id);
        $this->assertFalse($printer->tonerSupplies()->first()?->needs_identity_confirmation);
    }

    public function test_transient_unknown_snmp_reading_preserves_known_toner_level(): void
    {
        $printer = $this->makePrinter('192.168.1.34');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, $this->discovery([
            $this->supply('1', 'black', 'TK-5240K', 45),
        ]));

        $service->syncFromDiscovery($printer->fresh(), $this->discovery([
            $this->unknownSupply('1', 'black', 'TK-5240K'),
        ]));

        $service->syncFromDiscovery($printer->fresh(), $this->discovery([
            $this->supply('1', 'black', 'TK-5240K', 44),
        ]));

        $printer->refresh();

        $this->assertCount(1, $printer->tonerSupplies);
        $this->assertSame(0, TonerSupply::query()->onService()->count());
        $this->assertSame(44, $printer->tonerSupplies()->first()?->percentage);
        $this->assertFalse($printer->tonerSupplies->first()?->needs_identity_confirmation);
    }

    public function test_pending_slot_updates_snmp_without_new_replacement(): void
    {
        $printer = $this->makePrinter('192.168.1.28');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, $this->discovery([
            $this->supply('1', 'black', 'TK-5240K', 20),
        ]));

        $service->syncFromDiscovery($printer->fresh(), $this->discovery([
            $this->supply('1', 'black', 'TK-5240K', 85),
        ]));

        $provisional = $printer->fresh()->tonerSupplies()->first();
        $this->assertTrue($provisional?->needs_identity_confirmation);

        $service->syncFromDiscovery($printer->fresh(), $this->discovery([
            $this->supply('1', 'black', 'TK-5240K', 84),
        ]));

        $provisional->refresh();

        $this->assertSame(84, $provisional->percentage);
        $this->assertTrue($provisional->needs_identity_confirmation);
        $this->assertCount(1, $printer->fresh()->tonerSupplies);
    }

    public function test_missing_slot_moves_active_supply_to_service_with_slot_key(): void
    {
        $printer = $this->makePrinter('192.168.1.29');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, $this->discovery([
            $this->supply('3', 'yellow', 'TK-5240Y', 55),
        ]));

        $active = $printer->fresh()->tonerSupplies()->first();

        $service->syncFromDiscovery($printer->fresh(), $this->discovery([]));

        $serviceSupply = TonerSupply::query()->onService()->first();

        $this->assertCount(0, $printer->fresh()->tonerSupplies);
        $this->assertNotNull($serviceSupply);
        $this->assertSame($active?->id, $serviceSupply->id);
        $this->assertSame('3', $serviceSupply->history_slot_key);
    }

    public function test_partial_empty_response_does_not_move_active_supply_to_service(): void
    {
        $printer = $this->makePrinter('192.168.1.35');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, $this->discovery([
            $this->supply('3', 'yellow', 'TK-5240Y', 55),
        ]));

        $active = $printer->fresh()->tonerSupplies()->first();

        $service->syncFromDiscovery(
            $printer->fresh(),
            $this->discovery([]),
            isPartialResponse: true,
        );

        $printer->refresh();

        $this->assertCount(1, $printer->tonerSupplies);
        $this->assertSame(0, TonerSupply::query()->onService()->count());
        $this->assertSame($active?->id, $printer->tonerSupplies()->first()?->id);
    }

    public function test_install_from_service_reactivates_cartridge_and_moves_provisional_to_service(): void
    {
        $printer = $this->makePrinter('192.168.1.30');
        $service = $this->makeService();
        $identityService = new TonerSupplyIdentityService();

        $service->syncFromDiscovery($printer, $this->discovery([
            $this->supply('2', 'magenta', 'TK-5240M', 10),
        ]));

        $original = $printer->fresh()->tonerSupplies()->first();
        $original?->update(['comment' => 'Номер 2']);

        $service->syncFromDiscovery($printer->fresh(), $this->discovery([
            $this->supply('2', 'magenta', 'TK-5240M', 90),
        ]));

        $provisional = $printer->fresh()->tonerSupplies()->first();
        $serviceSupply = TonerSupply::query()->onService()->first();

        $identityService->installFromService($printer->fresh(), '2', $serviceSupply, $provisional);

        $printer->refresh();
        $active = $printer->tonerSupplies()->first();

        $this->assertNotNull($active);
        $this->assertSame($original?->id, $active->id);
        $this->assertSame('Номер 2', $active->comment);
        $this->assertSame(90, $active->percentage);
        $this->assertFalse($active->needs_identity_confirmation);
        $this->assertTrue(TonerSupply::query()->onService()->whereKey($provisional?->id)->exists());
    }

    public function test_confirm_provisional_as_new_keeps_supply_in_place(): void
    {
        $printer = $this->makePrinter('192.168.1.31');
        $service = $this->makeService();
        $identityService = new TonerSupplyIdentityService();

        $service->syncFromDiscovery($printer, $this->discovery([
            $this->supply('2', 'magenta', 'TK-5240M', 12),
        ]));

        $service->syncFromDiscovery($printer->fresh(), $this->discovery([
            $this->supply('2', 'magenta', 'TK-5240M', 88),
        ]));

        $provisional = $printer->fresh()->tonerSupplies()->first();

        $confirmedSupply = $identityService->confirmProvisionalAsNew($provisional, 'Картридж 2 слот 2');

        $printer->refresh();

        $this->assertSame($provisional?->id, $confirmedSupply->id);
        $this->assertSame('Картридж 2 слот 2', $confirmedSupply->comment);
        $this->assertSame(88, $confirmedSupply->percentage);
        $this->assertFalse($confirmedSupply->needs_identity_confirmation);
        $this->assertFalse(TonerSupply::query()->onService()->whereKey($provisional?->id)->exists());
        $this->assertCount(1, $printer->tonerSupplies);
    }

    public function test_service_supply_can_be_deleted_directly(): void
    {
        $printer = $this->makePrinter('192.168.1.32');
        $service = $this->makeService();

        $service->syncFromDiscovery($printer, $this->discovery([
            $this->supply('2', 'magenta', 'TK-5240M', 12),
        ]));

        $service->syncFromDiscovery($printer->fresh(), $this->discovery([
            $this->supply('2', 'magenta', 'TK-5240M', 88),
        ]));

        $serviceSupply = TonerSupply::query()->onService()->first();

        $this->assertNotNull($serviceSupply);

        $serviceSupply->delete();

        $this->assertDatabaseMissing('toner_supplies', [
            'id' => $serviceSupply->id,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $supplies
     */
    private function discovery(array $supplies): DiscoveredPrinterData
    {
        return new DiscoveredPrinterData(
            ipAddress: '192.168.1.25',
            discoveredName: 'Kyocera ECOSYS',
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
