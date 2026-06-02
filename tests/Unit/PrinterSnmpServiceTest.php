<?php

namespace Tests\Unit;

use App\Enums\TonerColor;
use App\Services\Printers\PrinterSnmpService;
use PHPUnit\Framework\TestCase;

class PrinterSnmpServiceTest extends TestCase
{
    public function test_it_detects_colors_from_keywords_and_part_numbers(): void
    {
        $service = new PrinterSnmpService();

        $this->assertSame(TonerColor::Black, $service->detectColor('Black toner'));
        $this->assertSame(TonerColor::Cyan, $service->detectColor('TK-5240C'));
        $this->assertSame(TonerColor::Magenta, $service->detectColor('Cartucho M'));
        $this->assertSame(TonerColor::Yellow, $service->detectColor('Желтый картридж'));
        $this->assertSame(TonerColor::Waste, $service->detectColor('Waste toner bottle'));
        $this->assertSame(TonerColor::Other, $service->detectColor('Imaging unit'));
        $this->assertSame(TonerColor::Unknown, $service->detectColor(null));
    }
}
