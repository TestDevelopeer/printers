<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cartridges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cartridge_set_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color')->default('other');
            $table->string('part_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cartridges');
    }
};
