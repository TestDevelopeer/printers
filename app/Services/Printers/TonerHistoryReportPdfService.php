<?php

namespace App\Services\Printers;

use App\Models\TonerSupply;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TonerHistoryReportPdfService
{
    /**
     * @param  Collection<int, TonerSupply>  $supplies
     */
    public function render(Collection $supplies): string
    {
        return Pdf::loadView('reports.toner-history', [
            'supplies' => $supplies,
            'generatedAt' => now(),
        ])
            ->setPaper('a4', 'landscape')
            ->output();
    }

    public function filename(): string
    {
        return Str::of('otchet-kartridzhi-')
            ->append(now()->format('Y-m-d-His'))
            ->append('.pdf')
            ->toString();
    }
}
