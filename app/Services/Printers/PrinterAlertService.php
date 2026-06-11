<?php

namespace App\Services\Printers;

use App\Enums\PrinterStatus;
use App\Models\Printer;
use App\Models\TonerSupply;
use App\Services\Notifications\TelegramBotService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PrinterAlertService
{
    private const LOW_TONER_TTL_SECONDS = 604800;

    private const PRINTER_STATUS_TTL_SECONDS = 86400;

    private const SUPPLY_REPLACEMENT_TTL_SECONDS = 3600;

    private const TRANSFER_TTL_SECONDS = 86400;

    public function __construct(
        private readonly TelegramBotService $telegramBotService,
    ) {
    }

    /**
     * @param  array<string, bool>  $previousLowTonerStates
     * @param  array<string, array{color: string, description: string|null}>  $previousActiveSupplies
     */
    public function dispatchAlerts(
        Printer $printer,
        ?PrinterStatus $previousStatus,
        array $previousLowTonerStates,
        array $previousActiveSupplies = [],
    ): void {
        $this->notifyPrinterStatusChange($printer, $previousStatus);
        $this->notifySupplyReplacementChanges($printer, $previousActiveSupplies);
        $this->notifyLowTonerChanges($printer, $previousLowTonerStates);
    }

    public function notifyForeignSupplyDetected(Printer $targetPrinter, TonerSupply $supply): void
    {
        $sourcePrinter = $supply->printer;

        if (! $sourcePrinter instanceof Printer) {
            return;
        }

        $this->sendOnce(
            $this->alertKey('transfer-detected', $targetPrinter->getKey(), $supply->getKey()),
            self::TRANSFER_TTL_SECONDS,
            implode("\n", [
                '⚠️ Обнаружен картридж от другого принтера',
                "🖨️ Новый принтер: {$targetPrinter->display_name}",
                "🌐 IP: {$targetPrinter->ip_address}",
                '🎨 Картридж: '.$this->formatSupplyLabel($supply->color_label, $supply->snmp_description),
                "↩️ Ранее принадлежал: {$sourcePrinter->display_name} ({$sourcePrinter->ip_address})",
                '📝 Действие: требуется подтверждение переноса',
            ]),
        );
    }

    public function notifyTransferConfirmed(TonerSupply $supply, Printer $previousPrinter, Printer $targetPrinter): void
    {
        $this->sendOnce(
            $this->alertKey('transfer-confirmed', $targetPrinter->getKey(), $supply->getKey()),
            self::TRANSFER_TTL_SECONDS,
            implode("\n", [
                '✅ Перенос картриджа подтвержден',
                "🖨️ Новый принтер: {$targetPrinter->display_name}",
                "🌐 IP: {$targetPrinter->ip_address}",
                '🎨 Картридж: '.$this->formatSupplyLabel($supply->color_label, $supply->snmp_description),
                "↪️ Перенесен из: {$previousPrinter->display_name} ({$previousPrinter->ip_address})",
            ]),
        );
    }

    private function notifyPrinterStatusChange(Printer $printer, ?PrinterStatus $previousStatus): void
    {
        $currentStatus = $printer->status;

        if ($currentStatus === null || $currentStatus === $previousStatus) {
            return;
        }

        if (in_array($currentStatus, [PrinterStatus::Offline, PrinterStatus::Error], true)) {
            $this->forgetAlertKeys($this->printerStatusKeys($printer, $currentStatus->value));
            $this->sendOnce(
                $this->alertKey('printer-status', $printer->getKey(), $currentStatus->value),
                self::PRINTER_STATUS_TTL_SECONDS,
                implode("\n", [
                    $currentStatus === PrinterStatus::Error ? '🚨 Изменение статуса принтера' : '📴 Изменение статуса принтера',
                    "🖨️ Принтер: {$printer->display_name}",
                    "🌐 IP: {$printer->ip_address}",
                    "📍 Новый статус: {$currentStatus->label()}",
                ]),
            );

            return;
        }

        if (
            $currentStatus === PrinterStatus::Online
            && in_array($previousStatus, [PrinterStatus::Offline, PrinterStatus::Error], true)
        ) {
            $this->forgetAlertKeys($this->printerStatusKeys($printer, $currentStatus->value));
            $this->sendOnce(
                $this->alertKey('printer-status', $printer->getKey(), $currentStatus->value),
                self::PRINTER_STATUS_TTL_SECONDS,
                implode("\n", [
                    '✅ Принтер снова в сети',
                    "🖨️ Принтер: {$printer->display_name}",
                    "🌐 IP: {$printer->ip_address}",
                    "📍 Новый статус: {$currentStatus->label()}",
                ]),
            );
        }
    }

    /**
     * @param  array<string, array{color: string, description: string|null}>  $previousActiveSupplies
     */
    private function notifySupplyReplacementChanges(Printer $printer, array $previousActiveSupplies): void
    {
        $currentSupplies = $printer->tonerSupplies
            ->filter(fn (TonerSupply $supply): bool => filled($supply->slot_key))
            ->mapWithKeys(fn (TonerSupply $supply): array => [
                $supply->slot_key => [
                    'color' => $supply->color_label,
                    'description' => $supply->snmp_description,
                ],
            ])
            ->all();

        foreach ($previousActiveSupplies as $slotKey => $previousSupply) {
            $currentSupply = $currentSupplies[$slotKey] ?? null;

            if ($currentSupply === null || $currentSupply === $previousSupply) {
                continue;
            }

            $this->sendOnce(
                $this->alertKey(
                    'supply-replacement',
                    $printer->getKey(),
                    sha1($slotKey.'|'.json_encode([$previousSupply, $currentSupply])),
                ),
                self::SUPPLY_REPLACEMENT_TTL_SECONDS,
                implode("\n", [
                    '🔄 Заменен картридж',
                    "🖨️ Принтер: {$printer->display_name}",
                    "🌐 IP: {$printer->ip_address}",
                    "🧩 Слот: {$slotKey}",
                    '⬅️ Было: '.$this->formatSupplyLabel($previousSupply['color'], $previousSupply['description']),
                    '➡️ Стало: '.$this->formatSupplyLabel($currentSupply['color'], $currentSupply['description']),
                ]),
            );
        }
    }

    /**
     * @param  array<string, bool>  $previousLowTonerStates
     */
    private function notifyLowTonerChanges(Printer $printer, array $previousLowTonerStates): void
    {
        foreach ($printer->tonerSupplies as $supply) {
            $currentState = $supply->isLow();
            $previousState = $previousLowTonerStates[$supply->identity_key] ?? null;

            if ($previousState === $currentState) {
                continue;
            }

            if ($currentState) {
                Cache::forget($this->alertKey('toner-recovered', $printer->getKey(), $supply->identity_key));
                $this->sendOnce(
                    $this->alertKey('low-toner', $printer->getKey(), $supply->identity_key),
                    self::LOW_TONER_TTL_SECONDS,
                    $this->formatLowTonerMessage($printer, $supply),
                );

                continue;
            }

            if ($previousState === true) {
                Cache::forget($this->alertKey('low-toner', $printer->getKey(), $supply->identity_key));
                $this->sendOnce(
                    $this->alertKey('toner-recovered', $printer->getKey(), $supply->identity_key),
                    self::LOW_TONER_TTL_SECONDS,
                    $this->formatRecoveredTonerMessage($printer, $supply),
                );
            }
        }
    }

    private function sendOnce(string $key, int $ttlSeconds, string $message): void
    {
        if (! Cache::add($key, true, now()->addSeconds($ttlSeconds))) {
            return;
        }

        $this->telegramBotService->sendMessage($message);
    }

    /**
     * @return array<int, string>
     */
    private function printerStatusKeys(Printer $printer, string $currentStatus): array
    {
        return Collection::make(['online', 'offline', 'error'])
            ->reject(fn (string $status): bool => $status === $currentStatus)
            ->map(fn (string $status): string => $this->alertKey('printer-status', $printer->getKey(), $status))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function forgetAlertKeys(array $keys): void
    {
        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    private function alertKey(string $type, int|string|null $printerId, int|string|null $subject): string
    {
        return sprintf(
            'printers:telegram:%s:%s:%s',
            $type,
            $printerId ?? 'unknown',
            sha1((string) ($subject ?? 'unknown')),
        );
    }

    private function formatLowTonerMessage(Printer $printer, TonerSupply $supply): string
    {
        return implode("\n", [
            '🟡 Низкий уровень тонера',
            "🖨️ Принтер: {$printer->display_name}",
            "🌐 IP: {$printer->ip_address}",
            '🎨 Картридж: '.$this->formatSupplyLabel($supply->color_label, $supply->snmp_description),
            "📉 Остаток: {$supply->percentage_display}",
        ]);
    }

    private function formatRecoveredTonerMessage(Printer $printer, TonerSupply $supply): string
    {
        return implode("\n", [
            '🟢 Тонер восстановился',
            "🖨️ Принтер: {$printer->display_name}",
            "🌐 IP: {$printer->ip_address}",
            '🎨 Картридж: '.$this->formatSupplyLabel($supply->color_label, $supply->snmp_description),
            "📈 Текущий уровень: {$supply->percentage_display}",
        ]);
    }

    private function formatSupplyLabel(string $color, ?string $description): string
    {
        $emoji = $this->colorEmoji($color);
        $prefix = "{$emoji} {$color}";

        if (blank($description)) {
            return $prefix;
        }

        return sprintf('%s (%s)', $prefix, $description);
    }

    private function colorEmoji(string $color): string
    {
        $normalized = mb_strtolower(trim($color));

        return match ($normalized) {
            'черный', 'black' => '⚫',
            'голубой', 'cyan' => '🔵',
            'пурпурный', 'magenta' => '🟣',
            'желтый', 'yellow' => '🟡',
            'отработка', 'waste' => '🗑️',
            default => '⚪',
        };
    }
}
