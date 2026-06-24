<?php

namespace App\Filament\Pages;

use App\Models\TonerSupply;
use App\Services\Printers\TonerHistoryReportPdfService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Session;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Cartridges extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Картриджи';

    protected static ?string $title = 'Картриджи';

    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.pages.cartridges';

    /**
     * @var array<int, int|string>
     */
    #[Session]
    public array $selectedSupplies = [];

    public int $perPage = 10;

    public function getSuppliesProperty(): LengthAwarePaginator
    {
        return TonerSupply::query()
            ->onService()
            ->orderByDesc('removed_at')
            ->orderByDesc('id')
            ->paginate($this->perPage);
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
            ->onService()
            ->whereIn('id', $ids)
            ->orderByDesc('removed_at')
            ->orderByDesc('id')
            ->get();

        if ($supplies->isEmpty()) {
            Notification::make()
                ->title('Картриджи не найдены')
                ->body('Выбранные картриджи больше не находятся на обслуживании.')
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
}
