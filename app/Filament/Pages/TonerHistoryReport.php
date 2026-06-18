<?php

namespace App\Filament\Pages;

use App\Models\TonerSupply;
use App\Services\Printers\TonerHistoryReportPdfService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Session;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TonerHistoryReport extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Отчет';

    protected static ?string $title = 'Отчет';

    protected string $view = 'filament.pages.toner-history-report';

    /**
     * @var array<int, int|string>
     */
    #[Session]
    public array $selectedSupplies = [];

    #[Session]
    public bool $serviceOnly = false;

    public int $perPage = 10;

    public function getSuppliesProperty(): LengthAwarePaginator
    {
        return $this->suppliesQuery()->paginate($this->perPage);
    }

    public function updatedServiceOnly(): void
    {
        $this->resetPage();
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
            ->tap(fn (Builder $query) => $this->applySuppliesFilter($query))
            ->whereIn('id', $ids)
            ->with('printer')
            ->orderByRaw('CASE WHEN removed_at IS NULL THEN 0 ELSE 1 END ASC')
            ->orderByDesc('last_seen_at')
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

    public function reportStatusLabel(TonerSupply $supply): string
    {
        if ($supply->removed_at === null) {
            return 'РђРєС‚РёРІРЅС‹Р№';
        }

        return $supply->is_on_service ? 'РќР° РѕР±СЃР»СѓР¶РёРІР°РЅРёРё' : 'Р’ РёСЃС‚РѕСЂРёРё';
    }

    private function suppliesQuery(): Builder
    {
        $query = TonerSupply::query();

        return $this->applySuppliesFilter($query);
    }

    private function applySuppliesFilter(Builder $query): Builder
    {
        return $query
            ->when($this->serviceOnly, fn (Builder $query) => $query->where('is_on_service', true))
            ->with('printer')
            ->orderByRaw('CASE WHEN removed_at IS NULL THEN 0 ELSE 1 END ASC')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('removed_at')
            ->orderByDesc('id');
    }
}
