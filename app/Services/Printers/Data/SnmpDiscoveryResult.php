<?php

namespace App\Services\Printers\Data;

final readonly class SnmpDiscoveryResult
{
    /**
     * @param  array<string, mixed>  $dump
     */
    public function __construct(
        public ?DiscoveredPrinterData $discovered,
        public array $dump,
        public bool $isPartialResponse = false,
        public ?string $failureReason = null,
    ) {
    }
}
