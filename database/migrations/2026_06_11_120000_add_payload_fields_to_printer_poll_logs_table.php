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
        Schema::table('printer_poll_logs', function (Blueprint $table) {
            $table->json('raw_snmp_dump')->nullable()->after('message');
            $table->json('normalized_payload')->nullable()->after('raw_snmp_dump');
            $table->string('exception_class')->nullable()->after('normalized_payload');
            $table->boolean('is_partial_response')->default(false)->after('exception_class');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('printer_poll_logs', function (Blueprint $table) {
            $table->dropColumn([
                'raw_snmp_dump',
                'normalized_payload',
                'exception_class',
                'is_partial_response',
            ]);
        });
    }
};
