<?php

namespace Tests\Feature;

use App\Enums\PrinterStatus;
use App\Enums\TonerColor;
use App\Models\Printer;
use App\Models\TonerSupply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrinterViewRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_printer_view_returns_200(): void
    {
        $user = User::factory()->create();
        $printer = $this->makePrinter();

        $this->actingAs($user)
            ->get("/admin/printers/{$printer->id}")
            ->assertOk();
    }

    public function test_printer_view_with_active_supply(): void
    {
        $user = User::factory()->create();
        $printer = $this->makePrinter();

        TonerSupply::query()->create([
            'printer_id' => $printer->id,
            'slot_key' => '1',
            'color' => TonerColor::Black->value,
            'detected_color' => TonerColor::Black->value,
            'snmp_description' => 'TK-5240K',
            'percentage' => 50,
            'max_capacity' => 100,
            'level' => 50,
            'is_known' => true,
            'installed_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->get("/admin/printers/{$printer->id}")
            ->assertOk();
    }

    public function test_printer_view_with_awaiting_slot(): void
    {
        $user = User::factory()->create();
        $printer = $this->makePrinter(['awaiting_slot_poll_keys' => ['2']]);

        $this->actingAs($user)
            ->get("/admin/printers/{$printer->id}")
            ->assertOk();
    }

    public function test_printer_view_with_provisional(): void
    {
        $user = User::factory()->create();
        $printer = $this->makePrinter();

        TonerSupply::query()->create([
            'printer_id' => $printer->id,
            'slot_key' => '1',
            'color' => TonerColor::Black->value,
            'snmp_description' => 'TK-5240K',
            'percentage' => 85,
            'is_known' => true,
            'is_color_manual' => true,
            'needs_identity_confirmation' => true,
            'replacement_detected_at' => now(),
            'installed_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->get("/admin/printers/{$printer->id}")
            ->assertOk();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makePrinter(array $overrides = []): Printer
    {
        return Printer::query()->create(array_merge([
            'name' => 'Test',
            'ip_address' => '192.168.1.'.random_int(50, 250),
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Online,
            'is_active' => true,
        ], $overrides));
    }
}
