<?php

namespace Tests\Unit;

use App\Services\Printers\NetworkScannerService;
use App\Services\Printers\PrinterPollingService;
use App\Services\Printers\PrinterSnmpService;
use InvalidArgumentException;
use Tests\TestCase;

class NetworkScannerServiceTest extends TestCase
{
    public function test_it_rejects_slow_synchronous_scan_ranges(): void
    {
        config()->set('printers.scan_timeout', 1000);
        config()->set('printers.scan_max_sync_seconds', 45);
        config()->set('printers.scan_estimated_requests_per_host', 8);

        $service = new NetworkScannerService(
            $this->createMock(PrinterSnmpService::class),
            $this->createMock(PrinterPollingService::class),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CIDR range is too large for synchronous UI scanning.');

        $service->assertCanRunSynchronously('192.168.1.0/24', 1000);
    }

    public function test_it_allows_small_synchronous_scan_ranges(): void
    {
        config()->set('printers.scan_timeout', 250);
        config()->set('printers.scan_max_sync_seconds', 45);
        config()->set('printers.scan_estimated_requests_per_host', 8);

        $service = new NetworkScannerService(
            $this->createMock(PrinterSnmpService::class),
            $this->createMock(PrinterPollingService::class),
        );

        $service->assertCanRunSynchronously('192.168.1.0/30', 250);

        $this->assertTrue(true);
    }

    public function test_it_supports_single_host_cidr_ranges(): void
    {
        config()->set('printers.scan_max_sync_seconds', 45);
        config()->set('printers.scan_estimated_requests_per_host', 8);

        $service = new NetworkScannerService(
            $this->createMock(PrinterSnmpService::class),
            $this->createMock(PrinterPollingService::class),
        );

        $service->assertCanRunSynchronously('127.0.0.1/32', 1000);

        $this->assertTrue(true);
    }
}
