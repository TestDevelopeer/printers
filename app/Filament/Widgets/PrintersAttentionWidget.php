<?php

namespace App\Filament\Widgets;

use App\Services\Printers\Data\PrinterAttentionItem;
use App\Services\Printers\PrinterAttentionService;
use Filament\Widgets\Concerns\CanPoll;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class PrintersAttentionWidget extends Widget
{
    use CanPoll;

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.printers-attention';

    protected function getPollingInterval(): ?string
    {
        return '30s';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var Collection<int, PrinterAttentionItem> $items */
        $items = app(PrinterAttentionService::class)->items();

        return [
            'items' => $items,
        ];
    }
}
