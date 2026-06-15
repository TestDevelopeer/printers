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

    public function sendActiveToService(TonerSupply $supply, ?string $color = null, ?string $comment = null): void
    {
        if ($supply->removed_at !== null) {
            throw new InvalidArgumentException('Картридж уже находится в истории.');
        }

        if ($supply->slot_key === null) {
            throw new InvalidArgumentException('Не удалось определить слот картриджа.');
        }

        $printer = $supply->printer;

        if (! $printer instanceof Printer) {
            throw new InvalidArgumentException('Не удалось определить принтер картриджа.');
        }

        DB::transaction(function () use ($supply, $color, $comment, $printer): void {
            $slotKey = (string) $supply->slot_key;

            if ($color !== null) {
                $supply->color = $color;
                $supply->is_color_manual = true;
            }

            if ($comment !== null) {
                $supply->comment = filled($comment) ? trim($comment) : null;
            }

            $supply->save();

            $this->moveSupplyToHistory($supply, $slotKey);

            $printer->addAwaitingSlotPollKey($slotKey);
        });
    }

    public function activateFromHistory(
        Printer $printer,
        string $slotKey,
        TonerSupply $historicalSupply,
        ?string $color = null,
        ?string $comment = null,
    ): TonerSupply {
        return DB::transaction(function () use ($printer, $slotKey, $historicalSupply, $color, $comment): TonerSupply {
            if ($historicalSupply->printer_id !== $printer->getKey() || $historicalSupply->removed_at === null) {
                throw new InvalidArgumentException('Выбранный картридж не найден в истории этого принтера.');
            }

            $historicalSlot = (string) ($historicalSupply->history_slot_key ?? $historicalSupply->slot_key ?? '');

            if ($historicalSlot !== $slotKey) {
                throw new InvalidArgumentException('Картридж не относится к выбранному слоту.');
            }

            $activeSupply = $this->findActiveSupplyBySlot($printer, $slotKey);

            if ($activeSupply !== null && $activeSupply->is($historicalSupply)) {
                throw new InvalidArgumentException('Картридж уже установлен в этом слоте.');
            }

            if ($activeSupply !== null) {
                $this->moveSupplyToHistory($activeSupply, $slotKey, isOnService: false);
            }

            if ($color !== null) {
                $historicalSupply->color = $color;
                $historicalSupply->is_color_manual = true;
            }

            if ($comment !== null) {
                $historicalSupply->comment = filled($comment) ? trim($comment) : null;
            }

            $historicalSupply->forceFill([
                'slot_key' => $slotKey,
                'removed_at' => null,
                'history_slot_key' => null,
                'needs_identity_confirmation' => false,
                'replacement_detected_at' => null,
                'installed_at' => $historicalSupply->installed_at ?? Carbon::now(),
                'last_seen_at' => Carbon::now(),
                'is_on_service' => false,
            ])->save();

            $printer->removeAwaitingSlotPollKey($slotKey);

            return $historicalSupply->fresh();
        });
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

    private function findActiveSupplyBySlot(Printer $printer, string $slotKey): ?TonerSupply
    {
        return TonerSupply::query()
            ->where('printer_id', $printer->getKey())
            ->where('slot_key', $slotKey)
            ->whereNull('removed_at')
            ->first();
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

    private function moveSupplyToHistory(TonerSupply $supply, string $slotKey, bool $isOnService = true): void
    {
        $supply->forceFill([
            'removed_at' => Carbon::now(),
            'history_slot_key' => $slotKey,
            'is_on_service' => $isOnService,
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
