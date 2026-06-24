<?php

namespace Tests\Feature;

use App\Enums\PrinterStatus;
use App\Enums\TonerColor;
use App\Filament\Pages\Cartridges;
use App\Models\Printer;
use App\Models\TonerSupply;
use App\Models\User;
use App\Services\Printers\TonerHistoryReportPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CartridgesPageTest extends TestCase
{
    use RefreshDatabase;

    private int $printerSequence = 60;

    public function test_page_renders_only_service_cartridges(): void
    {
        $user = User::factory()->create();
        $serviceSupply = $this->createServiceSupply('TK-5240K');
        $activeSupply = $this->createActiveSupply('TK-5240C');

        $this->actingAs($user)
            ->get(Cartridges::getUrl())
            ->assertSuccessful()
            ->assertSee('TK-5240K')
            ->assertDontSee('TK-5240C');

        $this->assertNotNull($serviceSupply->id);
        $this->assertNotNull($activeSupply->id);
    }

    public function test_generate_report_without_selection_shows_notification(): void
    {
        $user = User::factory()->create();
        $this->createServiceSupply('TK-5240K');

        Livewire::actingAs($user)
            ->test(Cartridges::class)
            ->call('generateReport')
            ->assertNotified();
    }

    public function test_generate_report_downloads_pdf_for_selected_supplies(): void
    {
        $user = User::factory()->create();
        $supply = $this->createServiceSupply('TK-5240K');

        Livewire::actingAs($user)
            ->test(Cartridges::class)
            ->set('selectedSupplies', [(string) $supply->id])
            ->call('generateReport')
            ->assertFileDownloaded();
    }

    public function test_generate_report_rejects_active_supply(): void
    {
        $user = User::factory()->create();
        $active = $this->createActiveSupply('TK-5240C');

        Livewire::actingAs($user)
            ->test(Cartridges::class)
            ->set('selectedSupplies', [(string) $active->id])
            ->call('generateReport')
            ->assertNotified();
    }

    public function test_selected_supplies_persist_when_switching_pages(): void
    {
        $user = User::factory()->create();

        $selected = null;

        foreach (range(1, 11) as $index) {
            $supply = $this->createServiceSupply(
                sprintf('PAGE-%02d', $index),
                historySlotKey: (string) $index,
            );

            if ($index === 11) {
                $selected = $supply;
            }
        }

        $this->assertNotNull($selected);

        Livewire::actingAs($user)
            ->test(Cartridges::class)
            ->set('selectedSupplies', [(string) $selected->id])
            ->call('setPage', 2)
            ->assertSet('selectedSupplies', [(string) $selected->id]);
    }

    public function test_pdf_service_renders_valid_document(): void
    {
        $supply = $this->createServiceSupply('TK-5240K');
        $service = app(TonerHistoryReportPdfService::class);

        $pdf = $service->render(TonerSupply::query()->whereKey($supply->id)->get());

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('otchet-kartridzhi-', $service->filename());
    }

    private function createServiceSupply(string $description, ?string $historySlotKey = '1'): TonerSupply
    {
        $printerIp = '192.168.1.'.(++$this->printerSequence);

        Printer::query()->create([
            'name' => 'Kyocera origin',
            'ip_address' => $printerIp,
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Online,
            'is_active' => true,
        ]);

        return TonerSupply::query()->create([
            'printer_id' => null,
            'slot_key' => null,
            'history_slot_key' => $historySlotKey,
            'color' => TonerColor::Black,
            'snmp_description' => $description,
            'level' => 12,
            'max_capacity' => 100,
            'percentage' => 12,
            'unit' => 'percent',
            'is_known' => true,
            'is_on_service' => true,
            'comment' => 'Списать',
            'removed_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
        ]);
    }

    private function createActiveSupply(string $description): TonerSupply
    {
        $printerIp = '192.168.1.'.(++$this->printerSequence);

        $printer = Printer::query()->create([
            'name' => 'Kyocera active',
            'ip_address' => $printerIp,
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Online,
            'is_active' => true,
        ]);

        return TonerSupply::query()->create([
            'printer_id' => $printer->id,
            'slot_key' => '1',
            'color' => TonerColor::Black,
            'snmp_description' => $description,
            'level' => 54,
            'max_capacity' => 100,
            'percentage' => 54,
            'unit' => 'percent',
            'is_known' => true,
            'is_on_service' => false,
            'installed_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
