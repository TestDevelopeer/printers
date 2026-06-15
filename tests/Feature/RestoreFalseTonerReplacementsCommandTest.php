<?php

namespace Tests\Feature;

use App\Models\Printer;
use App\Models\TonerSupply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RestoreFalseTonerReplacementsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_restores_supplies_created_by_false_replacements(): void
    {
        $cutoff = Carbon::parse('2026-06-11 19:31:00', 'Europe/Moscow');

        $printer = Printer::query()->create([
            'name' => 'Менеджеры',
            'ip_address' => '192.168.1.50',
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'is_active' => true,
        ]);

        $good = TonerSupply::query()->create([
            'printer_id' => $printer->id,
            'slot_key' => '2',
            'color' => 'magenta',
            'detected_color' => 'magenta',
            'snmp_description' => 'TK-5240M',
            'level' => 45,
            'max_capacity' => 100,
            'percentage' => 45,
            'unit' => 'percent',
            'is_known' => true,
            'installed_at' => $cutoff->copy()->subDays(10),
            'removed_at' => $cutoff->copy()->addMinute(),
            'history_slot_key' => '2',
            'is_on_service' => true,
            'needs_identity_confirmation' => false,
        ]);

        $provisional = TonerSupply::query()->create([
            'printer_id' => $printer->id,
            'slot_key' => '2',
            'color' => 'magenta',
            'detected_color' => 'magenta',
            'snmp_description' => 'TK-5240M',
            'level' => null,
            'max_capacity' => null,
            'percentage' => null,
            'unit' => 'percent',
            'is_known' => false,
            'installed_at' => $cutoff->copy()->addMinute(),
            'needs_identity_confirmation' => true,
            'replacement_detected_at' => $cutoff->copy()->addMinute(),
        ]);

        $this->artisan('printers:restore-false-replacements', [
            '--since' => '2026-06-11 19:31:00',
        ])->assertSuccessful();

        $this->assertDatabaseMissing('toner_supplies', ['id' => $provisional->id]);

        $good->refresh();

        $this->assertNull($good->removed_at);
        $this->assertNull($good->history_slot_key);
        $this->assertFalse($good->is_on_service);
        $this->assertFalse($good->needs_identity_confirmation);
        $this->assertSame(45, $good->percentage);
        $this->assertCount(1, $printer->fresh()->tonerSupplies);
    }
}
