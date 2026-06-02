<?php

namespace App\Models;

use App\Enums\TonerColor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cartridge extends Model
{
    /** @use HasFactory<\Database\Factories\CartridgeFactory> */
    use HasFactory;

    protected $fillable = [
        'cartridge_set_id',
        'name',
        'color',
        'part_number',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'color' => TonerColor::class,
        ];
    }

    public function cartridgeSet(): BelongsTo
    {
        return $this->belongsTo(CartridgeSet::class);
    }

    public function getColorLabelAttribute(): string
    {
        return ($this->color ?? TonerColor::Other)->label();
    }
}
