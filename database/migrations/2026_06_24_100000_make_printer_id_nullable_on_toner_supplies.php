<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE toner_supplies ALTER COLUMN printer_id DROP NOT NULL');
            DB::statement('ALTER TABLE toner_supplies DROP CONSTRAINT IF EXISTS toner_supplies_printer_id_foreign');
            DB::statement('ALTER TABLE toner_supplies ADD CONSTRAINT toner_supplies_printer_id_foreign FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE SET NULL');

            DB::statement('UPDATE toner_supplies SET printer_id = NULL WHERE is_on_service = true');

            return;
        }

        Schema::table('toner_supplies', function (Blueprint $table): void {
            $table->dropIndex(['printer_id', 'removed_at']);
            $table->dropIndex(['printer_id', 'slot_key']);
            $table->dropIndex(['printer_id', 'supply_signature']);
            $table->dropConstrainedForeignId('printer_id');
        });

        Schema::table('toner_supplies', function (Blueprint $table): void {
            $table->foreignId('printer_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->index(['printer_id', 'slot_key']);
        });

        DB::table('toner_supplies')
            ->where('is_on_service', true)
            ->update(['printer_id' => null]);
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        DB::table('toner_supplies')
            ->whereNull('printer_id')
            ->update(['printer_id' => 0]);

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE toner_supplies DROP CONSTRAINT IF EXISTS toner_supplies_printer_id_foreign');
            DB::statement('ALTER TABLE toner_supplies ALTER COLUMN printer_id SET NOT NULL');
            DB::statement('ALTER TABLE toner_supplies ADD CONSTRAINT toner_supplies_printer_id_foreign FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE CASCADE');

            return;
        }

        Schema::table('toner_supplies', function (Blueprint $table): void {
            $table->dropIndex(['printer_id', 'slot_key']);
            $table->dropConstrainedForeignId('printer_id');
        });

        Schema::table('toner_supplies', function (Blueprint $table): void {
            $table->foreignId('printer_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->index(['printer_id', 'removed_at']);
            $table->index(['printer_id', 'slot_key']);
            $table->index(['printer_id', 'supply_signature']);
        });
    }
};
