<?php

namespace Tests\Unit;

use App\Models\Printer;
use App\Models\PrinterMeterReading;
use App\Services\Printers\MeterReadingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MeterReadingServiceTest extends TestCase
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

    public function test_record_poll_creates_reading_with_total_pages(): void
    {
        $printer = $this->makePrinter();
        $service = app(MeterReadingService::class);

        $reading = $service->recordPoll($printer, 12345);

        $this->assertNotNull($reading);
        $this->assertSame($printer->id, $reading->printer_id);
        $this->assertSame(12345, $reading->total_pages);
        $this->assertSame(PrinterMeterReading::SOURCE_POLL, $reading->source);
        $this->assertSame(Carbon::today()->toDateString(), $reading->reading_date->toDateString());
    }

    public function test_record_poll_updates_existing_reading_for_same_day(): void
    {
        $printer = $this->makePrinter();
        $service = app(MeterReadingService::class);

        $service->recordPoll($printer, 100);
        Carbon::setTestNow(Carbon::today()->addHours(2));
        $service->recordPoll($printer, 150);

        $count = PrinterMeterReading::query()
            ->where('printer_id', $printer->id)
            ->where('source', PrinterMeterReading::SOURCE_POLL)
            ->count();

        $this->assertSame(1, $count);

        $latest = PrinterMeterReading::query()
            ->where('printer_id', $printer->id)
            ->where('source', PrinterMeterReading::SOURCE_POLL)
            ->first();

        $this->assertSame(150, $latest->total_pages);
    }

    public function test_take_daily_snapshot_uses_latest_poll_reading(): void
    {
        $printer = $this->makePrinter();
        $service = app(MeterReadingService::class);

        $service->recordPoll($printer, 7777);

        $count = $service->takeDailySnapshot();

        $this->assertSame(1, $count);

        $snapshot = PrinterMeterReading::query()
            ->where('printer_id', $printer->id)
            ->where('source', PrinterMeterReading::SOURCE_DAILY_SNAPSHOT)
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertSame(7777, $snapshot->total_pages);
    }

    public function test_take_daily_snapshot_creates_row_with_null_when_no_poll_data(): void
    {
        $printer = $this->makePrinter();
        $service = app(MeterReadingService::class);

        $count = $service->takeDailySnapshot();

        $this->assertSame(1, $count);

        $snapshot = PrinterMeterReading::query()
            ->where('printer_id', $printer->id)
            ->where('source', PrinterMeterReading::SOURCE_DAILY_SNAPSHOT)
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertNull($snapshot->total_pages);
    }

    public function test_take_daily_snapshot_skips_inactive_printers(): void
    {
        $active = $this->makePrinter(['is_active' => true]);
        $inactive = $this->makePrinter(['is_active' => false]);
        $service = app(MeterReadingService::class);

        $count = $service->takeDailySnapshot();

        $this->assertSame(1, $count);

        $this->assertSame(1, PrinterMeterReading::query()
            ->where('printer_id', $active->id)
            ->where('source', PrinterMeterReading::SOURCE_DAILY_SNAPSHOT)
            ->count());

        $this->assertSame(0, PrinterMeterReading::query()
            ->where('printer_id', $inactive->id)
            ->count());
    }

    public function test_take_daily_snapshot_is_idempotent_within_a_day(): void
    {
        $printer = $this->makePrinter();
        $service = app(MeterReadingService::class);

        $service->recordPoll($printer, 500);
        $service->takeDailySnapshot();
        $service->takeDailySnapshot();

        $this->assertSame(1, PrinterMeterReading::query()
            ->where('printer_id', $printer->id)
            ->where('source', PrinterMeterReading::SOURCE_DAILY_SNAPSHOT)
            ->count());
    }

    public function test_get_daily_breakdown_computes_deltas_for_seven_days(): void
    {
        $printer = $this->makePrinter();
        $service = app(MeterReadingService::class);
        $today = Carbon::today();

        Carbon::setTestNow($today->copy()->subDays(6)->setTime(10, 0));
        $service->recordPoll($printer, 100);

        Carbon::setTestNow($today->copy()->subDays(5)->setTime(10, 0));
        $service->recordPoll($printer, 110);

        Carbon::setTestNow($today->copy()->subDays(4)->setTime(10, 0));
        $service->recordPoll($printer, 130);

        Carbon::setTestNow($today->copy()->subDays(3)->setTime(10, 0));
        $service->recordPoll($printer, 150);

        Carbon::setTestNow($today->copy()->subDays(2)->setTime(10, 0));
        $service->recordPoll($printer, 200);

        Carbon::setTestNow($today->copy()->subDays(1)->setTime(10, 0));
        $service->recordPoll($printer, 250);

        Carbon::setTestNow($today->copy()->setTime(10, 0));
        $service->recordPoll($printer, 280);

        Carbon::setTestNow(Carbon::now());

        $breakdown = $service->getDailyBreakdown($printer, 7);

        $this->assertCount(7, $breakdown);

        $this->assertNull($breakdown[0]['delta']);
        $this->assertSame(100, $breakdown[0]['total_pages']);

        $this->assertSame(10, $breakdown[1]['delta']);
        $this->assertSame(20, $breakdown[2]['delta']);
        $this->assertSame(20, $breakdown[3]['delta']);
        $this->assertSame(50, $breakdown[4]['delta']);
        $this->assertSame(50, $breakdown[5]['delta']);
        $this->assertSame(30, $breakdown[6]['delta']);
        $this->assertTrue($breakdown[6]['is_today']);
    }

    public function test_get_daily_breakdown_detects_reset_and_uses_value_from_zero(): void
    {
        $printer = $this->makePrinter();
        $service = app(MeterReadingService::class);
        $today = Carbon::today();

        Carbon::setTestNow($today->copy()->subDays(3)->setTime(10, 0));
        $service->recordPoll($printer, 1000);

        Carbon::setTestNow($today->copy()->subDays(2)->setTime(10, 0));
        $service->recordPoll($printer, 1100);

        Carbon::setTestNow($today->copy()->subDays(1)->setTime(10, 0));
        $service->recordPoll($printer, 1200);

        Carbon::setTestNow($today->copy()->setTime(10, 0));
        $service->recordPoll($printer, 5);

        Carbon::setTestNow(Carbon::now());

        $breakdown = $service->getDailyBreakdown($printer, 4);

        $this->assertSame(100, $breakdown[1]['delta']);
        $this->assertSame(100, $breakdown[2]['delta']);
        $this->assertTrue($breakdown[3]['reset_detected']);
        $this->assertSame(5, $breakdown[3]['delta']);
    }

    public function test_get_daily_breakdown_uses_latest_reading_when_poll_and_snapshot_exist(): void
    {
        $printer = $this->makePrinter();
        $service = app(MeterReadingService::class);

        $today = Carbon::today();

        PrinterMeterReading::query()->create([
            'printer_id' => $printer->id,
            'reading_date' => $today->toDateString(),
            'recorded_at' => $today->copy()->setTime(0, 5),
            'total_pages' => 500,
            'source' => PrinterMeterReading::SOURCE_DAILY_SNAPSHOT,
        ]);

        PrinterMeterReading::query()->create([
            'printer_id' => $printer->id,
            'reading_date' => $today->toDateString(),
            'recorded_at' => $today->copy()->setTime(12, 0),
            'total_pages' => 700,
            'source' => PrinterMeterReading::SOURCE_POLL,
        ]);

        PrinterMeterReading::query()->create([
            'printer_id' => $printer->id,
            'reading_date' => $today->copy()->subDay()->toDateString(),
            'recorded_at' => $today->copy()->subDay()->setTime(12, 0),
            'total_pages' => 400,
            'source' => PrinterMeterReading::SOURCE_POLL,
        ]);

        $breakdown = $service->getDailyBreakdown($printer, 2);

        $this->assertSame(400, $breakdown[0]['total_pages']);
        $this->assertSame(700, $breakdown[1]['total_pages']);
        $this->assertSame(300, $breakdown[1]['delta']);
    }

    public function test_get_daily_breakdown_returns_empty_for_missing_days(): void
    {
        $printer = $this->makePrinter();
        $service = app(MeterReadingService::class);

        $breakdown = $service->getDailyBreakdown($printer, 3);

        $this->assertCount(3, $breakdown);
        foreach ($breakdown as $row) {
            $this->assertNull($row['total_pages']);
            $this->assertNull($row['delta']);
            $this->assertFalse($row['reset_detected']);
        }
        $this->assertTrue($breakdown[2]['is_today']);
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
            'status' => \App\Enums\PrinterStatus::Online,
            'is_active' => true,
        ], $overrides));
    }
}