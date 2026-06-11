<?php

namespace App\Services\Printers\Data;

use App\Models\Printer;

final readonly class PrinterPollResult
{
    /**
     * @param  array<string, mixed>|null  $rawSnmpDump
     * @param  array<string, mixed>|null  $normalizedPayload
     */
    public function __construct(
        public Printer $printer,
        public ?array $rawSnmpDump = null,
        public ?array $normalizedPayload = null,
        public ?string $exceptionClass = null,
        public bool $isPartialResponse = false,
    ) {
    }
}
