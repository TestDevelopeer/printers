<?php

namespace Tests\Feature;

use App\Filament\Pages\NetworkScan;
use App\Models\User;
use App\Services\Printers\Data\DiscoveredPrinterData;
use App\Services\Printers\NetworkScannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NetworkScanPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_network_scan_page_renders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(NetworkScan::getUrl())
            ->assertSuccessful();
    }

    public function test_network_scan_action_stores_discovered_printers(): void
    {
        $user = User::factory()->create();

        $discovered = new DiscoveredPrinterData(
            ipAddress: '192.168.1.90',
            discoveredName: 'Kyocera ECOSYS',
        );

        $scanner = $this->createMock(NetworkScannerService::class);
        $scanner->expects($this->once())->method('assertCanRunSynchronously');
        $scanner->expects($this->once())
            ->method('scan')
            ->willReturn([$discovered]);

        $this->app->instance(NetworkScannerService::class, $scanner);

        Livewire::actingAs($user)
            ->test(NetworkScan::class)
            ->callAction('runScan', data: [
                'cidr' => '192.168.1.0/30',
                'community' => 'public',
                'timeout' => 1000,
            ])
            ->assertNotified()
            ->assertSet('scanResults', [$discovered->toArray()]);
    }
}
