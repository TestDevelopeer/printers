<?php

namespace Database\Factories;

use App\Models\Printer;
use App\Models\PrinterMeterReading;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrinterMeterReading>
 */
class PrinterMeterReadingFactory extends Factory
{
    protected $model = PrinterMeterReading::class;

    public function definition(): array
    {
        return [
            'printer_id' => Printer::factory(),
            'reading_date' => now()->toDateString(),
            'recorded_at' => now(),
            'total_pages' => $this->faker->numberBetween(0, 1_000_000),
            'source' => PrinterMeterReading::SOURCE_POLL,
            'raw_data' => null,
        ];
    }

    public function poll(): static
    {
        return $this->state(fn (): array => [
            'source' => PrinterMeterReading::SOURCE_POLL,
        ]);
    }

    public function dailySnapshot(): static
    {
        return $this->state(fn (): array => [
            'source' => PrinterMeterReading::SOURCE_DAILY_SNAPSHOT,
        ]);
    }
}