<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterMeterReading extends Model
{
    /** @use HasFactory<\Database\Factories\PrinterMeterReadingFactory> */
    use HasFactory;

    public const SOURCE_POLL = 'poll';

    public const SOURCE_DAILY_SNAPSHOT = 'daily_snapshot';

    protected $fillable = [
        'printer_id',
        'reading_date',
        'recorded_at',
        'total_pages',
        'source',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'reading_date' => 'date:Y-m-d',
            'recorded_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function getSourceLabelAttribute(): string
    {
        return match ($this->source) {
            self::SOURCE_POLL => 'Опрос',
            self::SOURCE_DAILY_SNAPSHOT => 'Снимок 00:00',
            default => $this->source,
        };
    }
}