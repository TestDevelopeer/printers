<?php

namespace App\Filament\Pages;

use App\Enums\TonerColor;
use App\Models\TonerSupply;
use App\Services\Printers\TonerHistoryReportPdfService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

    public function edit_supplyAction(): Action
    {
        return Action::make('edit_supply')
            ->modalHeading('Изменить картридж')
            ->modalSubmitActionLabel('Сохранить')
            ->modalWidth('lg')
            ->form([
                Select::make('color')
                    ->label('Цвет')
                    ->options(self::colorOptions())
                    ->required(),
                TextInput::make('percentage')
                    ->label('% тонера')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(1)
                    ->suffix('%')
                    ->helperText('Ручное значение сохраняется до следующего опроса принтера.'),
                Textarea::make('comment')
                    ->label('Комментарий')
                    ->rows(3),
            ])
            ->fillForm(function (array $arguments): array {
                $supply = TonerSupply::query()->find($arguments['record'] ?? null);

                if (! $supply instanceof TonerSupply) {
                    return [];
                }

                return [
                    'color' => $supply->color?->value ?? TonerColor::Unknown->value,
                    'percentage' => $supply->percentage,
                    'comment' => $supply->comment,
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $supply = TonerSupply::query()->find($arguments['record'] ?? null);

                if (! $supply instanceof TonerSupply) {
                    Notification::make()
                        ->title('Картридж не найден')
                        ->danger()
                        ->send();

                    return;
                }

                $manualPercentage = array_key_exists('percentage', $data)
                    ? (is_numeric($data['percentage']) ? (int) $data['percentage'] : null)
                    : $supply->percentage;

                $supply->update([
                    'color' => $data['color'],
                    'is_color_manual' => true,
                    'percentage' => $manualPercentage,
                    'comment' => filled($data['comment'] ?? null) ? trim((string) $data['comment']) : null,
                ]);

                Notification::make()
                    ->title('Карточка картриджа обновлена')
                    ->success()
                    ->send();
            });
    }

    public function delete_supplyAction(): Action
    {
        return Action::make('delete_supply')
            ->modalHeading('Удалить картридж')
            ->modalDescription('Запись будет удалена из базы без возможности восстановления.')
            ->modalSubmitActionLabel('Удалить')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                $supply = TonerSupply::query()->find($arguments['record'] ?? null);

                if (! $supply instanceof TonerSupply) {
                    Notification::make()
                        ->title('Картридж не найден')
                        ->danger()
                        ->send();

                    return;
                }

                $id = $supply->getKey();
                $supply->delete();

                $this->selectedSupplies = array_values(array_filter(
                    $this->selectedSupplies,
                    static fn ($value): bool => (int) $value !== $id,
                ));

                Notification::make()
                    ->title('Картридж удалён')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, string>
     */
    private static function colorOptions(): array
    {
        $options = [];

        foreach (TonerColor::cases() as $color) {
            $options[$color->value] = $color->label();
        }

        return $options;
    }
}
