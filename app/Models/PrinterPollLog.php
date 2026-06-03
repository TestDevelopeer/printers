<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterPollLog extends Model
{
    /** @use HasFactory<\Database\Factories\PrinterPollLogFactory> */
    use HasFactory;

    protected $fillable = [
        'printer_id',
        'source',
        'status',
        'printer_name',
        'printer_ip',
        'printer_status',
        'message',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function getSourceLabelAttribute(): string
    {
        return match ($this->source) {
            'manual' => 'Ручной',
            'scheduled' => 'По расписанию',
            'cli' => 'CLI',
            default => $this->source,
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'running' => 'Выполняется',
            'success' => 'Успешно',
            'offline' => 'Не в сети',
            'error' => 'Ошибка',
            default => $this->status,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'running' => 'warning',
            'success' => 'success',
            'offline' => 'gray',
            'error' => 'danger',
            default => 'gray',
        };
    }
}
