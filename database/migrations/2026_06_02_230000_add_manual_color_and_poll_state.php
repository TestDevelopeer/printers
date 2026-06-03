<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('toner_supplies', function (Blueprint $table) {
            $table->string('detected_color')->nullable()->after('color');
            $table->boolean('is_color_manual')->default(false)->after('detected_color');
        });

        DB::table('toner_supplies')->update([
            'detected_color' => DB::raw('color'),
        ]);

        Schema::table('printers', function (Blueprint $table) {
            $table->boolean('is_polling')->default(false)->after('last_polled_at');
            $table->timestamp('manual_poll_requested_at')->nullable()->after('is_polling');
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropColumn([
                'is_polling',
                'manual_poll_requested_at',
            ]);
        });

        Schema::table('toner_supplies', function (Blueprint $table) {
            $table->dropColumn([
                'detected_color',
                'is_color_manual',
            ]);
        });
    }
};
