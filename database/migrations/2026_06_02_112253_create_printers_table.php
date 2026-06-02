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
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('discovered_name')->nullable();
            $table->ipAddress('ip_address')->unique();
            $table->string('mac_address')->nullable();
            $table->string('hostname')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable()->index();
            $table->string('location')->nullable();
            $table->string('snmp_community')->default('public');
            $table->string('snmp_version')->default('2c');
            $table->string('status')->default('unknown')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};
