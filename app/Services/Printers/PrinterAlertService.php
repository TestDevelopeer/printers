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
     */
    public function dispatchAlerts(
        Printer $printer,
        ?PrinterStatus $previousStatus,
        array $previousLowTonerStates,
    ): void {
        $this->notifyPrinterStatusChange($printer, $previousStatus);
        $this->notifyLowTonerChanges($printer, $previousLowTonerStates);
    }

    private function notifyPrinterStatusChange(Printer $printer, ?PrinterStatus $previousStatus): void
    {
        $currentStatus = $printer->status;

        if ($currentStatus === null || $currentStatus === $previousStatus) {
            return;
        }

        if (in_array($currentStatus, [PrinterStatus::Offline, PrinterStatus::Error], true)) {
            $this->telegramBotService->sendMessage(
                sprintf(
                    "Принтер %s (%s) сменил статус: %s.",
                    $printer->display_name,
                    $printer->ip_address,
                    $currentStatus->label(),
                ),
            );

            return;
        }

        if (
            $currentStatus === PrinterStatus::Online
            && in_array($previousStatus, [PrinterStatus::Offline, PrinterStatus::Error], true)
        ) {
            $this->telegramBotService->sendMessage(
                sprintf(
                    "Принтер %s (%s) снова в сети.",
                    $printer->display_name,
                    $printer->ip_address,
                ),
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
            "Низкий уровень тонера: %s (%s), %s, %s, остаток %s.",
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
            "Тонер восстановился: %s (%s), %s, %s, текущий уровень %s.",
            $printer->display_name,
            $printer->ip_address,
            $supply->color_label,
            $supply->snmp_description ?: 'без описания',
            $supply->percentage_display,
        );
    }
}
