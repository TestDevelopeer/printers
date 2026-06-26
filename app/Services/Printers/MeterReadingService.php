<?php

namespace App\Services\Printers;

use App\Models\Printer;
use App\Models\PrinterMeterReading;
use Illuminate\Support\Carbon;

class MeterReadingService
{
    /**
     * Record (or update) a poll-based meter reading for today.
     */
    public function recordPoll(Printer $printer, ?int $totalPages): ?PrinterMeterReading
    {
        if (! $printer->exists) {
            return null;
        }

        $today = Carbon::today()->toDateString();
        $now = Carbon::now();

        $payload = [
            'recorded_at' => $now,
        ];

        if ($totalPages !== null) {
            $payload['total_pages'] = $totalPages;
        }

        return PrinterMeterReading::query()->updateOrCreate(
            [
                'printer_id' => $printer->getKey(),
                'reading_date' => $today,
                'source' => PrinterMeterReading::SOURCE_POLL,
            ],
            $payload,
        );
    }

    /**
     * Take a 00:00 daily snapshot for every active printer.
     *
     * For each active printer, ensure a row with source=daily_snapshot exists for today.
     * Its total_pages is taken from the most recent poll reading of the printer that is
     * not older than 7 days. If none exists, total_pages stays null.
     */
    public function takeDailySnapshot(): int
    {
        $today = Carbon::today()->toDateString();
        $now = Carbon::now();
        $freshSince = $now->copy()->subDays(7);

        $count = 0;

        Printer::query()
            ->where('is_active', true)
            ->select(['id'])
            ->chunkById(200, function ($printers) use ($today, $now, $freshSince, &$count): void {
                foreach ($printers as $printer) {
                    $latest = PrinterMeterReading::query()
                        ->where('printer_id', $printer->id)
                        ->where('source', PrinterMeterReading::SOURCE_POLL)
                        ->where('recorded_at', '>=', $freshSince)
                        ->orderBy('reading_date', 'desc')
                        ->orderBy('recorded_at', 'desc')
                        ->first();

                    PrinterMeterReading::query()->updateOrCreate(
                        [
                            'printer_id' => $printer->id,
                            'reading_date' => $today,
                            'source' => PrinterMeterReading::SOURCE_DAILY_SNAPSHOT,
                        ],
                        [
                            'recorded_at' => $now,
                            'total_pages' => $latest?->total_pages,
                        ],
                    );

                    $count++;
                }
            });

        return $count;
    }

    public function getCurrentTotal(Printer $printer): ?PrinterMeterReading
    {
        if (! $printer->exists) {
            return null;
        }

        return PrinterMeterReading::query()
            ->where('printer_id', $printer->getKey())
            ->where('source', PrinterMeterReading::SOURCE_POLL)
            ->orderBy('reading_date', 'desc')
            ->orderBy('recorded_at', 'desc')
            ->first();
    }

    /**
     * Build a per-day breakdown for the last $days days (including today).
     *
     * For each day in the window, picks the most recent reading of any source
     * by recorded_at (so resets within a day are handled: the last reading
     * is the post-reset value, which is the "end of day").
     *
     * @return array<int, array{date: string, is_today: bool, total_pages: ?int, delta: ?int, reset_detected: bool}>
     */
    public function getDailyBreakdown(Printer $printer, int $days = 7): array
    {
        if (! $printer->exists) {
            return [];
        }

        $days = max(1, min(60, $days));
        $today = Carbon::today();
        $start = $today->copy()->subDays($days - 1);

        $readings = PrinterMeterReading::query()
            ->where('printer_id', $printer->getKey())
            ->whereBetween('reading_date', [$start->toDateString(), $today->toDateString()])
            ->orderBy('reading_date')
            ->orderBy('recorded_at')
            ->get();

        $byDate = $readings->groupBy(fn (PrinterMeterReading $r): string => $r->reading_date->toDateString());

        $rows = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i);
            $dateString = $date->toDateString();
            $dayReadings = $byDate->get($dateString, collect());

            /** @var PrinterMeterReading|null $last */
            $last = $dayReadings->sortByDesc('recorded_at')->first();

            $rows[] = [
                'date' => $dateString,
                'is_today' => $i === 0,
                'total_pages' => $last?->total_pages,
                'delta' => null,
                'reset_detected' => false,
            ];
        }

        for ($i = 0; $i < count($rows); $i++) {
            if ($i === 0) {
                continue;
            }

            $curr = $rows[$i]['total_pages'];
            $prev = $rows[$i - 1]['total_pages'];

            if ($curr === null || $prev === null) {
                continue;
            }

            if ($curr < $prev) {
                $rows[$i]['delta'] = $curr;
                $rows[$i]['reset_detected'] = true;

                continue;
            }

            $rows[$i]['delta'] = $curr - $prev;
        }

        return $rows;
    }
}