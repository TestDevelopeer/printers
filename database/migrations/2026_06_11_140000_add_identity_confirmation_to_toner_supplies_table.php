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
            $table->boolean('needs_identity_confirmation')->default(false)->after('is_on_service');
            $table->timestamp('replacement_detected_at')->nullable()->after('needs_identity_confirmation');
            $table->string('history_slot_key')->nullable()->after('slot_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('toner_supplies', function (Blueprint $table) {
            $table->dropColumn([
                'needs_identity_confirmation',
                'replacement_detected_at',
                'history_slot_key',
            ]);
        });
    }
};
