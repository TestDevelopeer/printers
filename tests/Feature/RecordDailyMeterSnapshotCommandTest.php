<?php

namespace Tests\Feature;

use App\Enums\PrinterStatus;
use App\Models\Printer;
use App\Models\PrinterMeterReading;
use App\Services\Printers\MeterReadingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecordDailyMeterSnapshotCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_creates_snapshot_for_active_printers(): void
    {
        $active = $this->makePrinter(['is_active' => true]);
        $inactive = $this->makePrinter(['is_active' => false]);

        app(MeterReadingService::class)->recordPoll($active, 1234);

        $this->artisan('printers:daily-meter-snapshot')
            ->expectsOutputToContain('Снимок счётчиков создан для принтеров: 1.')
            ->assertSuccessful();

        $this->assertSame(1, PrinterMeterReading::query()
            ->where('printer_id', $active->id)
            ->where('source', PrinterMeterReading::SOURCE_DAILY_SNAPSHOT)
            ->count());

        $this->assertSame(0, PrinterMeterReading::query()
            ->where('printer_id', $inactive->id)
            ->count());
    }

    public function test_command_is_idempotent_within_same_day(): void
    {
        $printer = $this->makePrinter();
        app(MeterReadingService::class)->recordPoll($printer, 500);

        $this->artisan('printers:daily-meter-snapshot')->assertSuccessful();
        $this->artisan('printers:daily-meter-snapshot')->assertSuccessful();

        $this->assertSame(1, PrinterMeterReading::query()
            ->where('printer_id', $printer->id)
            ->where('source', PrinterMeterReading::SOURCE_DAILY_SNAPSHOT)
            ->count());
    }

    public function test_command_writes_null_for_printer_without_recent_poll(): void
    {
        $printer = $this->makePrinter();
        // 8 days ago, outside the 7-day freshness window
        Carbon::setTestNow(Carbon::today()->subDays(8)->setTime(10, 0));
        app(MeterReadingService::class)->recordPoll($printer, 9999);
        Carbon::setTestNow();

        $this->artisan('printers:daily-meter-snapshot')->assertSuccessful();

        $snapshot = PrinterMeterReading::query()
            ->where('printer_id', $printer->id)
            ->where('source', PrinterMeterReading::SOURCE_DAILY_SNAPSHOT)
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertNull($snapshot->total_pages);
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