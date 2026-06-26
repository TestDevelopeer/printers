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
        Schema::create('printer_meter_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('printer_id')->constrained()->cascadeOnDelete();
            $table->date('reading_date');
            $table->timestamp('recorded_at');
            $table->unsignedBigInteger('total_pages')->nullable();
            $table->string('source', 20);
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['printer_id', 'reading_date', 'source'], 'printer_meter_readings_printer_date_source_uniq');
            $table->index(['printer_id', 'reading_date'], 'printer_meter_readings_printer_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('printer_meter_readings');
    }
};