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
    public function sendActiveToService(
        TonerSupply $supply,
        ?string $color = null,
        ?string $comment = null,
        ?int $percentage = null,
    ): void
    {
        if ($supply->removed_at !== null) {
            throw new InvalidArgumentException('Картридж уже не активен.');
        }

        if ($supply->slot_key === null) {
            throw new InvalidArgumentException('Не удалось определить слот картриджа.');
        }

        $printer = $supply->printer;

        if (! $printer instanceof Printer) {
            throw new InvalidArgumentException('Не удалось определить принтер картриджа.');
        }

        DB::transaction(function () use ($supply, $color, $comment, $percentage, $printer): void {
            $slotKey = (string) $supply->slot_key;

            if ($color !== null) {
                $supply->color = $color;
                $supply->is_color_manual = true;
            }

            if ($comment !== null) {
                $supply->comment = filled($comment) ? trim($comment) : null;
            }

            if ($percentage !== null) {
                $supply->percentage = $percentage;
            }

            $supply->save();

            $this->detachFromPrinter($supply, $slotKey);

            $printer->addAwaitingSlotPollKey($slotKey);
        });
    }

    public function installFromService(
        Printer $printer,
        string $slotKey,
        TonerSupply $serviceSupply,
        ?TonerSupply $provisional = null,
        ?string $color = null,
        ?string $comment = null,
        ?int $percentage = null,
    ): TonerSupply {
        if (! $serviceSupply->is_on_service || $serviceSupply->printer_id !== null) {
            throw new InvalidArgumentException('Картридж не находится на обслуживании.');
        }

        return DB::transaction(function () use ($printer, $slotKey, $serviceSupply, $provisional, $color, $comment, $percentage): TonerSupply {
            $activeSupply = $this->findActiveSupplyBySlot($printer, $slotKey);

            if ($activeSupply !== null && $activeSupply->is($serviceSupply)) {
                throw new InvalidArgumentException('Этот картридж уже установлен в слоте.');
            }

            if ($activeSupply !== null) {
                $this->detachFromPrinter($activeSupply, $slotKey);
            }

            if ($provisional !== null) {
                $payload = $this->snmpPayloadFrom($provisional);
                foreach ($payload as $field => $value) {
                    $serviceSupply->{$field} = $value;
                }
            }

            if ($color !== null) {
                $serviceSupply->color = $color;
                $serviceSupply->is_color_manual = true;
            }

            if ($comment !== null) {
                $serviceSupply->comment = filled($comment) ? trim($comment) : null;
            }

            if ($percentage !== null) {
                $serviceSupply->percentage = $percentage;
            }

            $serviceSupply->forceFill([
                'printer_id' => $printer->getKey(),
                'slot_key' => $slotKey,
                'history_slot_key' => null,
                'removed_at' => null,
                'is_on_service' => false,
                'needs_identity_confirmation' => false,
                'replacement_detected_at' => null,
                'installed_at' => $serviceSupply->installed_at ?? Carbon::now(),
                'last_seen_at' => Carbon::now(),
            ])->save();

            $printer->removeAwaitingSlotPollKey($slotKey);

            return $serviceSupply->fresh();
        });
    }

    public function confirmProvisionalAsNew(TonerSupply $provisional, string $comment): TonerSupply
    {
        if (! $provisional->needs_identity_confirmation || $provisional->removed_at !== null) {
            throw new InvalidArgumentException('Картридж не ожидает подтверждения.');
        }

        $provisional->forceFill([
            'needs_identity_confirmation' => false,
            'replacement_detected_at' => null,
            'comment' => trim($comment),
            'is_on_service' => false,
            'last_seen_at' => Carbon::now(),
        ])->save();

        return $provisional->fresh();
    }

    private function findActiveSupplyBySlot(Printer $printer, string $slotKey): ?TonerSupply
    {
        return TonerSupply::query()
            ->where('printer_id', $printer->getKey())
            ->where('slot_key', $slotKey)
            ->whereNull('removed_at')
            ->first();
    }

    private function detachFromPrinter(TonerSupply $supply, string $slotKey): void
    {
        $supply->forceFill([
            'printer_id' => null,
            'slot_key' => null,
            'history_slot_key' => $slotKey,
            'removed_at' => Carbon::now(),
            'is_on_service' => true,
            'needs_identity_confirmation' => false,
            'replacement_detected_at' => null,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function snmpPayloadFrom(TonerSupply $source): array
    {
        return [
            'snmp_description' => $source->snmp_description,
            'level' => $source->level,
            'max_capacity' => $source->max_capacity,
            'percentage' => $source->percentage,
            'unit' => $source->unit,
            'is_known' => $source->is_known,
            'raw_value' => $source->raw_value,
            'detected_color' => $source->detected_color?->value ?? $source->detected_color,
        ];
    }
}
