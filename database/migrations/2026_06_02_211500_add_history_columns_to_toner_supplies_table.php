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
        Schema::table('toner_supplies', function (Blueprint $table) {
            $table->string('slot_key')->nullable()->after('printer_id');
            $table->string('supply_signature')->nullable()->after('slot_key');
            $table->timestamp('installed_at')->nullable()->after('raw_value');
            $table->timestamp('removed_at')->nullable()->after('installed_at');
            $table->timestamp('last_seen_at')->nullable()->after('removed_at');

            $table->index(['printer_id', 'removed_at']);
            $table->index(['printer_id', 'slot_key']);
            $table->index(['printer_id', 'supply_signature']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('toner_supplies', function (Blueprint $table) {
            $table->dropIndex(['printer_id', 'removed_at']);
            $table->dropIndex(['printer_id', 'slot_key']);
            $table->dropIndex(['printer_id', 'supply_signature']);

            $table->dropColumn([
                'slot_key',
                'supply_signature',
                'installed_at',
                'removed_at',
                'last_seen_at',
            ]);
        });
    }
};
