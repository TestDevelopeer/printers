<?php

namespace Tests\Feature;

use App\Enums\PrinterAttentionType;
use App\Enums\PrinterStatus;
use App\Enums\TonerColor;
use App\Filament\Widgets\PrintersAttentionStats;
use App\Filament\Widgets\PrintersAttentionWidget;
use App\Models\Printer;
use App\Models\TonerSupply;
use App\Models\User;
use App\Services\Printers\PrinterAttentionService;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PrinterAttentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_attention_service_collects_all_issue_types(): void
    {
        $printer = $this->createPrinter();

        TonerSupply::query()->create([
            'printer_id' => $printer->id,
            'slot_key' => '1',
            'color' => TonerColor::Black,
            'snmp_description' => 'TK-5240K',
            'percentage' => 10,
            'is_known' => true,
        ]);

        TonerSupply::query()->create([
            'printer_id' => $printer->id,
            'slot_key' => '2',
            'color' => TonerColor::Yellow,
            'snmp_description' => 'TK-5240Y',
            'percentage' => 80,
            'is_known' => true,
            'needs_identity_confirmation' => true,
        ]);

        $printer->forceFill(['awaiting_slot_poll_keys' => ['3']])->save();

        $service = app(PrinterAttentionService::class);
        $counts = $service->counts();

        $this->assertSame(1, $counts['low_toner']);
        $this->assertSame(1, $counts['identity_confirmation']);
        $this->assertSame(1, $counts['empty_slot']);
        $this->assertSame(3, $counts['total']);

        $types = $service->items()->pluck('type')->all();

        $this->assertContains(PrinterAttentionType::LowToner, $types);
        $this->assertContains(PrinterAttentionType::IdentityConfirmation, $types);
        $this->assertContains(PrinterAttentionType::EmptySlot, $types);
    }

    public function test_dashboard_shows_attention_summary_widgets(): void
    {
        $user = User::factory()->create();
        $printer = $this->createPrinter();

        TonerSupply::query()->create([
            'printer_id' => $printer->id,
            'slot_key' => '1',
            'color' => TonerColor::Black,
            'snmp_description' => 'TK-5240K',
            'percentage' => 8,
            'is_known' => true,
        ]);

        $this->actingAs($user)
            ->get(Dashboard::getUrl())
            ->assertSuccessful()
            ->assertSee('Принтеры, требующие внимания')
            ->assertSee('Низкий тонер')
            ->assertSee('TK-5240K');

        Livewire::actingAs($user)
            ->test(PrintersAttentionStats::class)
            ->assertSee('1');

        Livewire::actingAs($user)
            ->test(PrintersAttentionWidget::class)
            ->assertSee('Kyocera офис')
            ->assertSee('#'.$printer->id);
    }

    private function createPrinter(): Printer
    {
        return Printer::query()->create([
            'name' => 'Kyocera офис',
            'ip_address' => '192.168.1.90',
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Online,
            'is_active' => true,
        ]);
    }
}
