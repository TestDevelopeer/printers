<?php

namespace App\Filament\Resources\Printers\Pages;

use App\Jobs\PollPrinterJob;
use App\Filament\Resources\Printers\PrinterResource;
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
                ->label('Опросить сейчас')
                ->icon('heroicon-m-arrow-path')
                ->action(function (): void {
                    PollPrinterJob::dispatch($this->record->id);

                    Notification::make()
                        ->title('Опрос поставлен в очередь')
                        ->body("Принтер {$this->record->display_name} будет опрошен в ближайшее время.")
                        ->success()
                        ->send();
                }),
            EditAction::make(),
        ];
    }
}
