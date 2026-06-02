<?php

namespace App\Models;

use App\Enums\TonerColor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TonerSupply extends Model
{
    /** @use HasFactory<\Database\Factories\TonerSupplyFactory> */
    use HasFactory;

    protected $fillable = [
        'printer_id',
        'color',
        'snmp_description',
        'level',
        'max_capacity',
        'percentage',
        'unit',
        'is_known',
        'raw_value',
    ];

    protected function casts(): array
    {
        return [
            'color' => TonerColor::class,
            'is_known' => 'boolean',
            'raw_value' => 'array',
        ];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function isLow(): bool
    {
        return $this->percentage !== null
            && $this->percentage <= config('printers.low_toner_threshold', 15);
    }

    public function getStatusLabelAttribute(): string
    {
        if (! $this->is_known || $this->percentage === null) {
            return 'Unknown';
        }

        return $this->isLow() ? 'Low' : 'OK';
    }

    public function getColorLabelAttribute(): string
    {
        return ($this->color ?? TonerColor::Unknown)->label();
    }

    public function getPercentageDisplayAttribute(): string
    {
        return $this->percentage === null ? 'Unknown' : "{$this->percentage}%";
    }
}
