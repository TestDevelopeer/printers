<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('toner_supplies', function (Blueprint $table): void {
            $table->dropIndex(['transfer_target_printer_id', 'removed_at']);
            $table->dropConstrainedForeignId('transfer_target_printer_id');
            $table->dropColumn('transfer_detected_at');
        });
    }

    public function down(): void
    {
        Schema::table('toner_supplies', function (Blueprint $table): void {
            $table->foreignId('transfer_target_printer_id')
                ->nullable()
                ->after('is_on_service')
                ->constrained('printers')
                ->nullOnDelete();
            $table->timestamp('transfer_detected_at')
                ->nullable()
                ->after('transfer_target_printer_id');

            $table->index(['transfer_target_printer_id', 'removed_at']);
        });
    }
};
