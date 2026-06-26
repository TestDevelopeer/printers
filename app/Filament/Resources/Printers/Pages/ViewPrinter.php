<?php

namespace App\Filament\Resources\Printers\Pages;

use App\Filament\Support\ManualPrinterPoll;
use App\Jobs\PollPrinterJob;
use App\Filament\Resources\Printers\PrinterResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

class ViewPrinter extends ViewRecord
{
    protected static string $resource = PrinterResource::class;

    public function hydrate(): void
    {
        if ($this->record?->exists) {
            $this->record->refresh();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('poll')
                ->label(fn (): string => $this->record->is_polling ? 'Опрос выполняется' : 'Опросить сейчас')
                ->icon('heroicon-m-arrow-path')
                ->color(fn (): string => $this->record->is_polling ? 'warning' : 'gray')
                ->disabled(fn (): bool => (bool) $this->record->is_polling)
                ->action(function (): void {
                    try {
                        ManualPrinterPoll::run($this->record);

                        Notification::make()
                            ->title('Опрос поставлен в очередь')
                            ->body("Принтер {$this->record->display_name} поставлен в очередь на опрос. Результат будет в логах.")
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Ошибка опроса')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }

                    $this->record->refresh();
                }),
            EditAction::make(),
        ];
    }
}
