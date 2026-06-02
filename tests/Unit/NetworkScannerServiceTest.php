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
        config()->set('printers.scan_max_sync_seconds', 15);
        config()->set('printers.scan_concurrency', 16);
        config()->set('printers.scan_ping_concurrency', 32);
        config()->set('printers.scan_estimated_snmp_hosts', 16);
        config()->set('printers.scan_estimated_snmp_seconds_per_host', 2);

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
        config()->set('printers.scan_max_sync_seconds', 90);
        config()->set('printers.scan_concurrency', 16);
        config()->set('printers.scan_ping_concurrency', 32);
        config()->set('printers.scan_estimated_snmp_hosts', 16);
        config()->set('printers.scan_estimated_snmp_seconds_per_host', 2);

        $service = new NetworkScannerService(
            $this->createMock(PrinterSnmpService::class),
            $this->createMock(PrinterPollingService::class),
        );

        $service->assertCanRunSynchronously('192.168.1.0/30', 250);

        $this->assertTrue(true);
    }

    public function test_it_supports_single_host_cidr_ranges(): void
    {
        config()->set('printers.scan_max_sync_seconds', 90);
        config()->set('printers.scan_concurrency', 16);
        config()->set('printers.scan_ping_concurrency', 32);
        config()->set('printers.scan_estimated_snmp_hosts', 16);
        config()->set('printers.scan_estimated_snmp_seconds_per_host', 2);

        $service = new NetworkScannerService(
            $this->createMock(PrinterSnmpService::class),
            $this->createMock(PrinterPollingService::class),
        );

        $service->assertCanRunSynchronously('127.0.0.1/32', 1000);

        $this->assertTrue(true);
    }

    public function test_it_allows_a_typical_class_c_range_with_default_ui_limits(): void
    {
        config()->set('printers.scan_timeout', 1000);
        config()->set('printers.scan_max_sync_seconds', 90);
        config()->set('printers.scan_concurrency', 16);
        config()->set('printers.scan_ping_concurrency', 32);
        config()->set('printers.scan_estimated_snmp_hosts', 16);
        config()->set('printers.scan_estimated_snmp_seconds_per_host', 2);

        $service = new NetworkScannerService(
            $this->createMock(PrinterSnmpService::class),
            $this->createMock(PrinterPollingService::class),
        );

        $service->assertCanRunSynchronously('192.168.1.0/24', 1000);

        $this->assertTrue(true);
    }
}
