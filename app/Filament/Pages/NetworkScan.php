<?php

namespace App\Filament\Pages;

use App\Services\Printers\Data\DiscoveredPrinterData;
use App\Services\Printers\NetworkScannerService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;

class NetworkScan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?string $navigationLabel = 'Сканирование сети';

    protected static ?string $title = 'Сканирование сети';

    protected string $view = 'filament.pages.network-scan';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $scanResults = [];

    /**
     * @var array<int, int|string>
     */
    public array $selectedResults = [];

    /**
     * @var array<string, mixed>
     */
    public array $lastScanOptions = [];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runScan')
                ->label('Запустить сканирование')
                ->icon('heroicon-m-magnifying-glass')
                ->schema([
                    TextInput::make('cidr')
                        ->label('CIDR')
                        ->required()
                        ->default('192.168.1.0/24'),
                    TextInput::make('community')
                        ->label('Community')
                        ->required()
                        ->default(config('printers.default_snmp_community', 'public')),
                    TextInput::make('timeout')
                        ->label('Таймаут (мс)')
                        ->numeric()
                        ->required()
                        ->default(config('printers.scan_timeout', 1000)),
                ])
                ->action(function (array $data, NetworkScannerService $networkScannerService): void {
                    try {
                        set_time_limit(max(120, (int) config('printers.scan_max_sync_seconds', 90) + 30));

                        $networkScannerService->assertCanRunSynchronously(
                            $data['cidr'],
                            (int) $data['timeout'],
                        );

                        $this->lastScanOptions = $data;
                        $this->selectedResults = [];

                        $this->scanResults = array_map(
                            static fn (DiscoveredPrinterData $result): array => $result->toArray(),
                            $networkScannerService->scan(
                                $data['cidr'],
                                $data['community'],
                                (int) $data['timeout'],
                            ),
                        );

                        Notification::make()
                            ->title('Сканирование завершено')
                            ->body(sprintf('Найдено кандидатов в принтеры: %d.', count($this->scanResults)))
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Сканирование завершилось ошибкой')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    public function importSelected(NetworkScannerService $networkScannerService): void
    {
        $selected = collect($this->selectedResults)
            ->map(fn ($index) => $this->scanResults[(int) $index] ?? null)
            ->filter()
            ->map(fn (array $row) => DiscoveredPrinterData::fromArray($row))
            ->values();

        if ($selected->isEmpty()) {
            Notification::make()
                ->title('Ничего не выбрано')
                ->warning()
                ->send();

            return;
        }

        try {
            $networkScannerService->import($selected->all());

            Notification::make()
                ->title('Принтеры импортированы')
                ->body(sprintf('Импортировано принтеров: %d.', $selected->count()))
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Ошибка импорта')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
