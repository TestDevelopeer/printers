<?php

namespace App\Filament\Pages;

use App\Models\TonerSupply;
use App\Services\Printers\TonerHistoryReportPdfService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TonerHistoryReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Отчет';

    protected static ?string $title = 'Отчет';

    protected string $view = 'filament.pages.toner-history-report';

    /**
     * @var array<int, int|string>
     */
    public array $selectedSupplies = [];

    public bool $serviceOnly = false;

    public function getHistorySuppliesProperty(): EloquentCollection
    {
        return $this->historySuppliesQuery()->get();
    }

    public function updatedServiceOnly(): void
    {
        $visibleIds = $this->historySuppliesQuery()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->selectedSupplies = collect($this->selectedSupplies)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => in_array($id, $visibleIds, true))
            ->values()
            ->all();
    }

    public function generateReport(TonerHistoryReportPdfService $pdfService): ?StreamedResponse
    {
        $ids = collect($this->selectedSupplies)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            Notification::make()
                ->title('Ничего не выбрано')
                ->body('Отметьте хотя бы один картридж в таблице.')
                ->warning()
                ->send();

            return null;
        }

        $supplies = TonerSupply::query()
            ->tap(fn (Builder $query) => $this->applyHistorySuppliesFilter($query))
            ->whereIn('id', $ids)
            ->with('printer')
            ->orderByDesc('removed_at')
            ->orderByDesc('id')
            ->get();

        if ($supplies->isEmpty()) {
            Notification::make()
                ->title('Картриджи не найдены')
                ->body('Выбранные записи отсутствуют в истории.')
                ->warning()
                ->send();

            return null;
        }

        return response()->streamDownload(
            fn () => print($pdfService->render($supplies)),
            $pdfService->filename(),
            ['Content-Type' => 'application/pdf'],
        );
    }

    private function historySuppliesQuery(): Builder
    {
        $query = TonerSupply::query()->inHistory();

        return $this->applyHistorySuppliesFilter($query);
    }

    private function applyHistorySuppliesFilter(Builder $query): Builder
    {
        return $query
            ->when($this->serviceOnly, fn (Builder $query) => $query->where('is_on_service', true))
            ->with('printer')
            ->orderByDesc('removed_at')
            ->orderByDesc('id');
    }
}
