<?php

namespace App\Services\Printers;

use App\Models\Printer;
use App\Models\TonerSupply;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TonerSupplyIdentityService
{
    /**
     * @return Collection<int, TonerSupply>
     */
    public function slotHistory(Printer $printer, string $slotKey): Collection
    {
        return TonerSupply::query()
            ->where('printer_id', $printer->getKey())
            ->whereNotNull('removed_at')
            ->where(function ($query) use ($slotKey): void {
                $query->where('history_slot_key', $slotKey)
                    ->orWhere('slot_key', $slotKey);
            })
            ->orderByDesc('removed_at')
            ->get();
    }

    public function selectFromHistory(Printer $printer, string $slotKey, TonerSupply $historicalSupply): TonerSupply
    {
        return DB::transaction(function () use ($printer, $slotKey, $historicalSupply): TonerSupply {
            $provisional = $this->findActiveProvisionalSupply($printer, $slotKey);

            if ($provisional === null) {
                throw new InvalidArgumentException('В слоте нет картриджа, ожидающего подтверждения.');
            }

            if ($historicalSupply->printer_id !== $printer->getKey() || $historicalSupply->removed_at === null) {
                throw new InvalidArgumentException('Выбранный картридж не найден в истории этого принтера.');
            }

            $this->moveSupplyToHistory($provisional, $slotKey);

            $historicalSupply->forceFill(array_merge(
                $this->snmpPayloadFrom($provisional),
                [
                    'slot_key' => $slotKey,
                    'removed_at' => null,
                    'history_slot_key' => null,
                    'needs_identity_confirmation' => false,
                    'replacement_detected_at' => null,
                    'installed_at' => $historicalSupply->installed_at ?? Carbon::now(),
                    'last_seen_at' => Carbon::now(),
                    'is_on_service' => false,
                ],
            ))->save();

            return $historicalSupply->fresh();
        });
    }

    public function deleteFromHistory(TonerSupply $supply): void
    {
        if ($supply->removed_at === null) {
            throw new InvalidArgumentException('Можно удалять только картриджи из истории.');
        }

        if ($supply->needs_identity_confirmation) {
            throw new InvalidArgumentException('Нельзя удалить картридж, ожидающий подтверждения.');
        }

        $supply->delete();
    }

    public function saveAsNew(Printer $printer, string $slotKey, string $comment): TonerSupply
    {
        return DB::transaction(function () use ($printer, $slotKey, $comment): TonerSupply {
            $provisional = $this->findActiveProvisionalSupply($printer, $slotKey);

            if ($provisional === null) {
                throw new InvalidArgumentException('В слоте нет картриджа, ожидающего подтверждения.');
            }

            $provisional->forceFill([
                'needs_identity_confirmation' => false,
                'replacement_detected_at' => null,
                'comment' => trim($comment),
                'is_on_service' => false,
                'last_seen_at' => Carbon::now(),
            ])->save();

            return $provisional->fresh();
        });
    }

    private function findActiveProvisionalSupply(Printer $printer, string $slotKey): ?TonerSupply
    {
        return TonerSupply::query()
            ->where('printer_id', $printer->getKey())
            ->where('slot_key', $slotKey)
            ->whereNull('removed_at')
            ->where('needs_identity_confirmation', true)
            ->first();
    }

    private function moveSupplyToHistory(TonerSupply $supply, string $slotKey): void
    {
        $supply->forceFill([
            'removed_at' => Carbon::now(),
            'history_slot_key' => $slotKey,
            'is_on_service' => true,
            'needs_identity_confirmation' => false,
            'replacement_detected_at' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function snmpPayloadFrom(TonerSupply $source, array $overrides = []): array
    {
        return array_merge([
            'snmp_description' => $source->snmp_description,
            'level' => $source->level,
            'max_capacity' => $source->max_capacity,
            'percentage' => $source->percentage,
            'unit' => $source->unit,
            'is_known' => $source->is_known,
            'raw_value' => $source->raw_value,
            'detected_color' => $source->detected_color?->value ?? $source->detected_color,
        ], $overrides);
    }
}
