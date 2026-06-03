<?php

namespace App\Services\Printers;

use App\Enums\PrinterStatus;
use App\Models\Printer;
use App\Models\TonerSupply;
use App\Services\Notifications\TelegramBotService;

class PrinterAlertService
{
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

        $this->telegramBotService->sendMessage(implode("\n", [
            '⚠️ Обнаружен картридж от другого принтера',
            "🖨️ Новый принтер: {$targetPrinter->display_name}",
            "🌐 IP: {$targetPrinter->ip_address}",
            '🎨 Картридж: '.$this->formatSupplyLabel($supply->color_label, $supply->snmp_description),
            "↩️ Ранее принадлежал: {$sourcePrinter->display_name} ({$sourcePrinter->ip_address})",
            '📝 Действие: требуется подтверждение переноса',
        ]));
    }

    public function notifyTransferConfirmed(TonerSupply $supply, Printer $previousPrinter, Printer $targetPrinter): void
    {
        $this->telegramBotService->sendMessage(implode("\n", [
            '✅ Перенос картриджа подтвержден',
            "🖨️ Новый принтер: {$targetPrinter->display_name}",
            "🌐 IP: {$targetPrinter->ip_address}",
            '🎨 Картридж: '.$this->formatSupplyLabel($supply->color_label, $supply->snmp_description),
            "↪️ Перенесен из: {$previousPrinter->display_name} ({$previousPrinter->ip_address})",
        ]));
    }

    private function notifyPrinterStatusChange(Printer $printer, ?PrinterStatus $previousStatus): void
    {
        $currentStatus = $printer->status;

        if ($currentStatus === null || $currentStatus === $previousStatus) {
            return;
        }

        if (in_array($currentStatus, [PrinterStatus::Offline, PrinterStatus::Error], true)) {
            $this->telegramBotService->sendMessage(implode("\n", [
                $currentStatus === PrinterStatus::Error ? '🚨 Изменение статуса принтера' : '📴 Изменение статуса принтера',
                "🖨️ Принтер: {$printer->display_name}",
                "🌐 IP: {$printer->ip_address}",
                "📍 Новый статус: {$currentStatus->label()}",
            ]));

            return;
        }

        if (
            $currentStatus === PrinterStatus::Online
            && in_array($previousStatus, [PrinterStatus::Offline, PrinterStatus::Error], true)
        ) {
            $this->telegramBotService->sendMessage(implode("\n", [
                '✅ Принтер снова в сети',
                "🖨️ Принтер: {$printer->display_name}",
                "🌐 IP: {$printer->ip_address}",
                "📍 Новый статус: {$currentStatus->label()}",
            ]));
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

            $this->telegramBotService->sendMessage(implode("\n", [
                '🔄 Заменен картридж',
                "🖨️ Принтер: {$printer->display_name}",
                "🌐 IP: {$printer->ip_address}",
                "🧩 Слот: {$slotKey}",
                '⬅️ Было: '.$this->formatSupplyLabel($previousSupply['color'], $previousSupply['description']),
                '➡️ Стало: '.$this->formatSupplyLabel($currentSupply['color'], $currentSupply['description']),
            ]));
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
                $this->telegramBotService->sendMessage($this->formatLowTonerMessage($printer, $supply));

                continue;
            }

            if ($previousState === true) {
                $this->telegramBotService->sendMessage($this->formatRecoveredTonerMessage($printer, $supply));
            }
        }
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
