<?php

namespace Tests\Feature;

use App\Enums\PrinterStatus;
use App\Filament\Resources\Printers\Pages\ViewPrinter;
use App\Models\Printer;
use App\Models\TonerSupply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PrinterViewRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_printer_view_renders_without_500(): void
    {
        $user = User::factory()->create();
        $printer = Printer::query()->create([
            'name' => 'Test',
            'ip_address' => '192.168.1.50',
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Online,
            'is_active' => true,
        ]);

        TonerSupply::query()->create([
            'printer_id' => $printer->id,
            'slot_key' => '1',
            'color' => 'black',
            'snmp_description' => 'TK-5240K',
            'percentage' => 50,
            'is_known' => true,
            'installed_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(ViewPrinter::class, ['record' => $printer->id])
            ->assertSuccessful();
    }

    public function test_printer_view_renders_with_awaiting_slot(): void
    {
        $user = User::factory()->create();
        $printer = Printer::query()->create([
            'name' => 'Test',
            'ip_address' => '192.168.1.51',
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Online,
            'is_active' => true,
            'awaiting_slot_poll_keys' => ['2', '3'],
        ]);

        Livewire::actingAs($user)
            ->test(ViewPrinter::class, ['record' => $printer->id])
            ->assertSuccessful();
    }

    public function test_printer_view_renders_with_service_supply(): void
    {
        $user = User::factory()->create();
        $printer = Printer::query()->create([
            'name' => 'Test',
            'ip_address' => '192.168.1.52',
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Online,
            'is_active' => true,
        ]);

        TonerSupply::query()->create([
            'printer_id' => null,
            'slot_key' => null,
            'history_slot_key' => '1',
            'color' => 'black',
            'snmp_description' => 'SVC-TK-5240K',
            'percentage' => 12,
            'is_known' => true,
            'is_on_service' => true,
            'removed_at' => now()->subDay(),
        ]);

        Livewire::actingAs($user)
            ->test(ViewPrinter::class, ['record' => $printer->id])
            ->assertSuccessful();
    }
}
