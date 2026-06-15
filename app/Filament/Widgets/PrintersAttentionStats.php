<?php

namespace App\Filament\Widgets;

use App\Services\Printers\PrinterAttentionService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PrintersAttentionStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 0;

    protected ?string $heading = 'Принтеры, требующие внимания';

    protected function getPollingInterval(): ?string
    {
        return '30s';
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $counts = app(PrinterAttentionService::class)->counts();

        return [
            Stat::make('Низкий тонер', $counts['low_toner'])
                ->description('Картриджи ниже порога')
                ->color($counts['low_toner'] > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-exclamation-triangle'),
            Stat::make('Выбрать картридж', $counts['identity_confirmation'])
                ->description('После замены нужно подтверждение')
                ->color($counts['identity_confirmation'] > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-question-mark-circle'),
            Stat::make('Пустые слоты', $counts['empty_slot'])
                ->description('Ожидают опроса принтера')
                ->color($counts['empty_slot'] > 0 ? 'gray' : 'success')
                ->icon('heroicon-o-inbox'),
        ];
    }
}
