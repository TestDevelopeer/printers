<?php

namespace App\Services\Printers;

use App\Enums\PrinterAttentionType;
use App\Models\Printer;
use App\Models\TonerSupply;
use App\Services\Printers\Data\PrinterAttentionItem;
use Illuminate\Support\Collection;

class PrinterAttentionService
{
    /**
     * @return Collection<int, PrinterAttentionItem>
     */
    public function items(): Collection
    {
        $items = $this->lowTonerItems()
            ->merge($this->identityConfirmationItems())
            ->merge($this->emptySlotItems());

        return $items
            ->sortBy(fn (PrinterAttentionItem $item): array => [
                $item->printer->display_name,
                TonerSupply::slotSortValue($item->slotKey),
                $item->type->sortOrder(),
            ])
            ->values();
    }

    /**
     * @return array{low_toner: int, identity_confirmation: int, empty_slot: int, total: int}
     */
    public function counts(): array
    {
        $items = $this->items();

        return [
            'low_toner' => $items->where('type', PrinterAttentionType::LowToner)->count(),
            'identity_confirmation' => $items->where('type', PrinterAttentionType::IdentityConfirmation)->count(),
            'empty_slot' => $items->where('type', PrinterAttentionType::EmptySlot)->count(),
            'total' => $items->count(),
        ];
    }

    /**
     * @return Collection<int, PrinterAttentionItem>
     */
    private function lowTonerItems(): Collection
    {
        $threshold = config('printers.low_toner_threshold', 15);

        return TonerSupply::query()
            ->whereNull('removed_at')
            ->where('needs_identity_confirmation', false)
            ->whereNotNull('percentage')
            ->where('percentage', '<=', $threshold)
            ->whereHas('printer', fn ($query) => $query->where('is_active', true))
            ->with('printer')
            ->get()
            ->map(fn (TonerSupply $supply): PrinterAttentionItem => new PrinterAttentionItem(
                type: PrinterAttentionType::LowToner,
                printer: $supply->printer,
                slotKey: $supply->slot_key,
                supply: $supply,
            ));
    }

    /**
     * @return Collection<int, PrinterAttentionItem>
     */
    private function identityConfirmationItems(): Collection
    {
        return TonerSupply::query()
            ->whereNull('removed_at')
            ->where('needs_identity_confirmation', true)
            ->whereHas('printer', fn ($query) => $query->where('is_active', true))
            ->with('printer')
            ->get()
            ->map(fn (TonerSupply $supply): PrinterAttentionItem => new PrinterAttentionItem(
                type: PrinterAttentionType::IdentityConfirmation,
                printer: $supply->printer,
                slotKey: $supply->slot_key,
                supply: $supply,
            ));
    }

    /**
     * @return Collection<int, PrinterAttentionItem>
     */
    private function emptySlotItems(): Collection
    {
        return Printer::query()
            ->where('is_active', true)
            ->whereNotNull('awaiting_slot_poll_keys')
            ->get()
            ->flatMap(function (Printer $printer): Collection {
                return collect($printer->awaiting_slot_placeholders)
                    ->map(fn (array $placeholder): PrinterAttentionItem => new PrinterAttentionItem(
                        type: PrinterAttentionType::EmptySlot,
                        printer: $printer,
                        slotKey: $placeholder['slot_key'],
                        supply: null,
                    ));
            });
    }
}
