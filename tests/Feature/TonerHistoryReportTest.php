<?php

namespace Tests\Feature;

use App\Enums\PrinterStatus;
use App\Enums\TonerColor;
use App\Filament\Pages\TonerHistoryReport;
use App\Models\Printer;
use App\Models\TonerSupply;
use App\Models\User;
use App\Services\Printers\TonerHistoryReportPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TonerHistoryReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_page_renders(): void
    {
        $user = User::factory()->create();
        $this->createHistoricalSupply();

        $this->actingAs($user)
            ->get(TonerHistoryReport::getUrl())
            ->assertSuccessful()
            ->assertSee('Картриджи в истории')
            ->assertSee('TK-5240K');
    }

    public function test_generate_report_without_selection_shows_notification(): void
    {
        $user = User::factory()->create();
        $this->createHistoricalSupply();

        Livewire::actingAs($user)
            ->test(TonerHistoryReport::class)
            ->call('generateReport')
            ->assertNotified();
    }

    public function test_generate_report_downloads_pdf_for_selected_supplies(): void
    {
        $user = User::factory()->create();
        $supply = $this->createHistoricalSupply();

        Livewire::actingAs($user)
            ->test(TonerHistoryReport::class)
            ->set('selectedSupplies', [(string) $supply->id])
            ->call('generateReport')
            ->assertFileDownloaded();
    }

    public function test_pdf_service_renders_valid_document(): void
    {
        $supply = $this->createHistoricalSupply();
        $service = app(TonerHistoryReportPdfService::class);

        $pdf = $service->render(TonerSupply::query()->whereKey($supply->id)->with('printer')->get());

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('otchet-kartridzhi-', $service->filename());
    }

    private function createHistoricalSupply(): TonerSupply
    {
        $printer = Printer::query()->create([
            'name' => 'Kyocera офис',
            'ip_address' => '192.168.1.90',
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Online,
            'is_active' => true,
        ]);

        return TonerSupply::query()->create([
            'printer_id' => $printer->id,
            'slot_key' => '1',
            'history_slot_key' => '1',
            'color' => TonerColor::Black,
            'snmp_description' => 'TK-5240K',
            'percentage' => 12,
            'is_known' => true,
            'comment' => 'Списать',
            'removed_at' => now()->subDay(),
        ]);
    }
}
