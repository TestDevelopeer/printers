<?php

namespace App\Services\Printers\Data;

use App\Enums\PrinterAttentionType;
use App\Filament\Resources\Printers\PrinterResource;
use App\Models\Printer;
use App\Models\TonerSupply;

final readonly class PrinterAttentionItem
{
    public function __construct(
        public PrinterAttentionType $type,
        public Printer $printer,
        public ?string $slotKey,
        public ?TonerSupply $supply,
    ) {}

    public function cartridgeId(): ?int
    {
        return $this->supply?->getKey();
    }

    public function cartridgeLabel(): ?string
    {
        return $this->supply?->display_name;
    }

    public function tonerLevel(): ?string
    {
        return $this->supply?->percentage_display;
    }

    public function printerViewUrl(): string
    {
        return PrinterResource::getUrl('view', ['record' => $this->printer]);
    }
}
