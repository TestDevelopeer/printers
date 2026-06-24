<?php

namespace Tests\Feature;

use App\Enums\PrinterStatus;
use App\Enums\TonerColor;
use App\Filament\Pages\Cartridges;
use App\Models\Printer;
use App\Models\TonerSupply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CartridgesActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_action_updates_service_supply(): void
    {
        $user = User::factory()->create();
        $supply = $this->createServiceSupply();

        Livewire::actingAs($user)
            ->test(Cartridges::class)
            ->callAction('edit_supply', [
                'color' => TonerColor::Magenta->value,
                'percentage' => 42,
                'comment' => 'После заправки',
            ], ['record' => $supply->id])
            ->assertHasNoActionErrors();

        $supply->refresh();
        $this->assertSame(TonerColor::Magenta->value, $supply->color?->value);
        $this->assertTrue($supply->is_color_manual);
        $this->assertSame(42, $supply->percentage);
        $this->assertSame('После заправки', $supply->comment);
        $this->assertTrue($supply->is_on_service);
        $this->assertNull($supply->printer_id);
    }

    public function test_edit_action_keeps_service_state(): void
    {
        $user = User::factory()->create();
        $supply = $this->createServiceSupply();

        Livewire::actingAs($user)
            ->test(Cartridges::class)
            ->callAction('edit_supply', [
                'color' => TonerColor::Cyan->value,
                'percentage' => null,
                'comment' => null,
            ], ['record' => $supply->id])
            ->assertHasNoActionErrors();

        $supply->refresh();
        $this->assertNull($supply->printer_id);
        $this->assertNull($supply->slot_key);
        $this->assertTrue($supply->is_on_service);
    }

    public function test_delete_action_removes_service_supply(): void
    {
        $user = User::factory()->create();
        $supply = $this->createServiceSupply();

        Livewire::actingAs($user)
            ->test(Cartridges::class)
            ->callAction('delete_supply', [], ['record' => $supply->id])
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('toner_supplies', ['id' => $supply->id]);
    }

    public function test_edit_on_missing_supply_shows_notification(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Cartridges::class)
            ->callAction('edit_supply', [
                'color' => TonerColor::Black->value,
            ], ['record' => 99999])
            ->assertNotified();
    }

    public function test_delete_clears_selection(): void
    {
        $user = User::factory()->create();
        $supply = $this->createServiceSupply();

        $page = Livewire::actingAs($user)->test(Cartridges::class);
        $page->set('selectedSupplies', [(string) $supply->id])
            ->callAction('delete_supply', [], ['record' => $supply->id])
            ->assertHasNoActionErrors();

        $this->assertSame([], $page->get('selectedSupplies'));
    }

    private function createServiceSupply(): TonerSupply
    {
        $printer = Printer::query()->create([
            'name' => 'Origin',
            'ip_address' => '192.168.1.99',
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Online,
            'is_active' => true,
        ]);

        return TonerSupply::query()->create([
            'printer_id' => null,
            'slot_key' => null,
            'history_slot_key' => '1',
            'color' => TonerColor::Black->value,
            'snmp_description' => 'TK-5240K',
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
}
