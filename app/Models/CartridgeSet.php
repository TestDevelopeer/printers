<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CartridgeSet extends Model
{
    /** @use HasFactory<\Database\Factories\CartridgeSetFactory> */
    use HasFactory;

    protected $fillable = [
        'printer_id',
        'name',
        'description',
    ];

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function cartridges(): HasMany
    {
        return $this->hasMany(Cartridge::class);
    }
}
