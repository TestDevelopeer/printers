<?php

namespace Tests\Feature;

use App\Enums\PrinterStatus;
use App\Filament\Support\ManualPrinterPoll;
use App\Jobs\PollPrinterJob;
use App\Models\Printer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ManualPrinterPollTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_marks_printer_as_polling_and_dispatches_async_job(): void
    {
        Bus::fake();

        $printer = Printer::query()->create([
            'name' => 'Test',
            'ip_address' => '192.168.1.10',
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Online,
            'is_active' => true,
        ]);

        ManualPrinterPoll::run($printer);

        $printer->refresh();
        $this->assertTrue($printer->is_polling);
        $this->assertNotNull($printer->manual_poll_requested_at);

        Bus::assertDispatched(PollPrinterJob::class, function (PollPrinterJob $job) use ($printer): bool {
            return $job->printerId === $printer->id
                && $job->source === 'manual'
                && $job->createProvisionalForEmptySlots === false;
        });
    }

    public function test_run_passes_create_provisional_flag_to_job(): void
    {
        Bus::fake();

        $printer = Printer::query()->create([
            'name' => 'Test',
            'ip_address' => '192.168.1.11',
            'snmp_community' => 'public',
            'snmp_version' => '2c',
            'status' => PrinterStatus::Online,
            'is_active' => true,
        ]);

        ManualPrinterPoll::run($printer, createProvisionalForEmptySlots: true);

        Bus::assertDispatched(PollPrinterJob::class, function (PollPrinterJob $job): bool {
            return $job->createProvisionalForEmptySlots === true;
        });
    }
}