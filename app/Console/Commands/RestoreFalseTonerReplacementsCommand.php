<?php

namespace App\Console\Commands;

use App\Models\Printer;
use App\Models\TonerSupply;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RestoreFalseTonerReplacementsCommand extends Command
{
    protected $signature = 'printers:restore-false-replacements
                            {--since=2026-06-11 19:31:00 : Момент начала ложных срабатываний (Europe/Moscow)}
                            {--dry-run : Только показать план восстановления без изменений в БД}';

    protected $description = 'Удалить ложные замены картриджей и восстановить состояние до указанной даты.';

    public function handle(): int
    {
        $cutoff = Carbon::parse(
            (string) $this->option('since'),
            'Europe/Moscow',
        )->utc();

        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            'Восстановление картриджей до состояния на %s (%s UTC)%s',
            Carbon::parse((string) $this->option('since'), 'Europe/Moscow')->toDateTimeString(),
            $cutoff->toDateTimeString(),
            $dryRun ? ' [dry-run]' : '',
        ));

        $restoredSlots = 0;
        $deletedSupplies = 0;

        Printer::query()
            ->orderBy('name')
            ->each(function (Printer $printer) use ($cutoff, $dryRun, &$restoredSlots, &$deletedSupplies): void {
                $slotKeys = TonerSupply::query()
                    ->where('printer_id', $printer->getKey())
                    ->where(function ($query) use ($cutoff): void {
                        $query->where('installed_at', '>=', $cutoff)
                            ->orWhere('removed_at', '>=', $cutoff)
                            ->orWhere('replacement_detected_at', '>=', $cutoff);
                    })
                    ->get()
                    ->flatMap(fn (TonerSupply $supply): array => array_filter([
                        $supply->slot_key,
                        $supply->history_slot_key,
                    ]))
                    ->unique()
                    ->values();

                foreach ($slotKeys as $slotKey) {
                    $result = $this->restoreSlot($printer, (string) $slotKey, $cutoff, $dryRun);

                    if ($result === null) {
                        continue;
                    }

                    $restoredSlots++;
                    $deletedSupplies += $result['deleted'];

                    $this->line(sprintf(
                        '  %s / слот %s: восстановлен #%d (%s), удалено записей: %d',
                        $printer->display_name,
                        $slotKey,
                        $result['restored_id'],
                        $result['restored_label'],
                        $result['deleted'],
                    ));
                }
            });

        if ($restoredSlots === 0) {
            $this->warn('Записей для восстановления не найдено.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Готово: восстановлено слотов %d, удалено записей %d.',
            $restoredSlots,
            $deletedSupplies,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{restored_id: int, restored_label: string, deleted: int}|null
     */
    private function restoreSlot(Printer $printer, string $slotKey, Carbon $cutoff, bool $dryRun): ?array
    {
        $good = TonerSupply::query()
            ->where('printer_id', $printer->getKey())
            ->where('slot_key', $slotKey)
            ->where('installed_at', '<', $cutoff)
            ->where('needs_identity_confirmation', false)
            ->orderByDesc('installed_at')
            ->first();

        if ($good === null) {
            return null;
        }

        $toDelete = TonerSupply::query()
            ->where('printer_id', $printer->getKey())
            ->where('id', '!=', $good->getKey())
            ->where(function ($query) use ($slotKey): void {
                $query->where('slot_key', $slotKey)
                    ->orWhere('history_slot_key', $slotKey);
            })
            ->where(function ($query) use ($cutoff): void {
                $query->where('installed_at', '>=', $cutoff)
                    ->orWhere('removed_at', '>=', $cutoff)
                    ->orWhere('replacement_detected_at', '>=', $cutoff)
                    ->orWhere('needs_identity_confirmation', true);
            })
            ->get();

        if ($toDelete->isEmpty() && $good->removed_at === null && ! $good->needs_identity_confirmation) {
            return null;
        }

        if ($dryRun) {
            return [
                'restored_id' => $good->getKey(),
                'restored_label' => $good->display_name,
                'deleted' => $toDelete->count(),
            ];
        }

        DB::transaction(function () use ($good, $toDelete): void {
            foreach ($toDelete as $supply) {
                $supply->delete();
            }

            $good->forceFill([
                'removed_at' => null,
                'history_slot_key' => null,
                'is_on_service' => false,
                'needs_identity_confirmation' => false,
                'replacement_detected_at' => null,
            ])->save();
        });

        return [
            'restored_id' => $good->getKey(),
            'restored_label' => $good->display_name,
            'deleted' => $toDelete->count(),
        ];
    }
}
