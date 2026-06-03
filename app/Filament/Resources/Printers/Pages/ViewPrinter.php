<?php

namespace App\Filament\Resources\Printers\Pages;

use App\Filament\Resources\Printers\PrinterResource;
use App\Jobs\PollPrinterJob;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPrinter extends ViewRecord
{
    protected static string $resource = PrinterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('poll')
                ->label(fn (): string => $this->record->is_polling ? 'Опрос выполняется' : 'Опросить сейчас')
                ->icon('heroicon-m-arrow-path')
                ->color(fn (): string => $this->record->is_polling ? 'warning' : 'gray')
                ->disabled(fn (): bool => (bool) $this->record->is_polling)
                ->action(function (): void {
                    $this->record->forceFill([
                        'is_polling' => true,
                        'manual_poll_requested_at' => now(),
                    ])->save();

                    PollPrinterJob::dispatch($this->record->id);

                    $this->record->refresh();

                    Notification::make()
                        ->title('Опрос поставлен в очередь')
                        ->body("Принтер {$this->record->display_name} опрашивается в фоне.")
                        ->success()
                        ->send();
                }),
            EditAction::make(),
        ];
    }
}
