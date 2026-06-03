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

    private function notifyPrinterStatusChange(Printer $printer, ?PrinterStatus $previousStatus): void
    {
        $currentStatus = $printer->status;

        if ($currentStatus === null || $currentStatus === $previousStatus) {
            return;
        }

        if (in_array($currentStatus, [PrinterStatus::Offline, PrinterStatus::Error], true)) {
            $this->telegramBotService->sendMessage(sprintf(
                'Принтер %s (%s) сменил статус: %s.',
                $printer->display_name,
                $printer->ip_address,
                $currentStatus->label(),
            ));

            return;
        }

        if (
            $currentStatus === PrinterStatus::Online
            && in_array($previousStatus, [PrinterStatus::Offline, PrinterStatus::Error], true)
        ) {
            $this->telegramBotService->sendMessage(sprintf(
                'Принтер %s (%s) снова в сети.',
                $printer->display_name,
                $printer->ip_address,
            ));
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

            if ($currentSupply === null) {
                continue;
            }

            if ($currentSupply === $previousSupply) {
                continue;
            }

            $this->telegramBotService->sendMessage(sprintf(
                'В принтере %s (%s) заменен картридж в слоте %s: было %s, стало %s.',
                $printer->display_name,
                $printer->ip_address,
                $slotKey,
                $this->formatSupplyLabel($previousSupply['color'], $previousSupply['description']),
                $this->formatSupplyLabel($currentSupply['color'], $currentSupply['description']),
            ));
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
        return sprintf(
            'Низкий уровень тонера: %s (%s), %s, %s, остаток %s.',
            $printer->display_name,
            $printer->ip_address,
            $supply->color_label,
            $supply->snmp_description ?: 'без описания',
            $supply->percentage_display,
        );
    }

    private function formatRecoveredTonerMessage(Printer $printer, TonerSupply $supply): string
    {
        return sprintf(
            'Тонер восстановился: %s (%s), %s, %s, текущий уровень %s.',
            $printer->display_name,
            $printer->ip_address,
            $supply->color_label,
            $supply->snmp_description ?: 'без описания',
            $supply->percentage_display,
        );
    }

    private function formatSupplyLabel(string $color, ?string $description): string
    {
        if (blank($description)) {
            return $color;
        }

        return sprintf('%s (%s)', $color, $description);
    }
}
