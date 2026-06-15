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

    public function __construct(
        private readonly TelegramBotService $telegramBotService,
    ) {}

    /**
     * @param  array<string, bool>  $previousLowTonerStates
     * @param  array<int, array{slot_key: string, supply: TonerSupply}>  $detectedReplacements
     */
    public function dispatchAlerts(
        Printer $printer,
        ?PrinterStatus $previousStatus,
        array $previousLowTonerStates,
        array $detectedReplacements = [],
    ): void {
        $replacementSlotKeys = array_map(
            static fn (array $replacement): string => $replacement['slot_key'],
            $detectedReplacements,
        );

        $this->notifyPrinterStatusChange($printer, $previousStatus);

        foreach ($detectedReplacements as $replacement) {
            $this->notifyCartridgeReplacementDetected(
                $printer,
                $replacement['slot_key'],
                $replacement['supply'],
            );
        }

        $this->notifyLowTonerChanges($printer, $previousLowTonerStates, $replacementSlotKeys);
    }

    public function notifyCartridgeReplacementDetected(
        Printer $printer,
        string $slotKey,
        TonerSupply $provisionalSupply,
    ): void {
        $this->sendOnce(
            $this->alertKey(
                'cartridge-replacement',
                $printer->getKey(),
                $slotKey.'|'.$provisionalSupply->getKey(),
            ),
            self::SUPPLY_REPLACEMENT_TTL_SECONDS,
            implode("\n", array_filter([
                '🔄 Заменён картридж',
                "🖨️ Принтер: {$printer->display_name}",
                ...$this->formatCartridgeContext($printer, $provisionalSupply, $slotKey),
                "🌐 IP: {$printer->ip_address}",
                '🎨 Картридж: '.$this->formatSupplyLabel(
                    $provisionalSupply->color_label,
                    $provisionalSupply->snmp_description,
                ),
                $provisionalSupply->percentage !== null
                    ? "📈 Уровень: {$provisionalSupply->percentage_display}"
                    : null,
                '📝 Требуется выбрать картридж в админке',
            ])),
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
     * @param  array<string, bool>  $previousLowTonerStates
     * @param  array<int, string>  $replacementSlotKeys
     */
    private function notifyLowTonerChanges(
        Printer $printer,
        array $previousLowTonerStates,
        array $replacementSlotKeys = [],
    ): void {
        foreach ($printer->tonerSupplies as $supply) {
            if (in_array($supply->slot_key, $replacementSlotKeys, true)) {
                continue;
            }

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
            ...$this->formatCartridgeContext($printer, $supply),
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
            ...$this->formatCartridgeContext($printer, $supply),
            "🌐 IP: {$printer->ip_address}",
            '🎨 Картридж: '.$this->formatSupplyLabel($supply->color_label, $supply->snmp_description),
            "📈 Текущий уровень: {$supply->percentage_display}",
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function formatCartridgeContext(Printer $printer, TonerSupply $supply, ?string $slotKey = null): array
    {
        $slot = $slotKey ?? $supply->slot_key ?? '—';

        return [
            "🆔 Принтер: #{$printer->getKey()}",
            "🧩 Слот: {$slot}",
            "🆔 Картридж: #{$supply->getKey()}",
        ];
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
