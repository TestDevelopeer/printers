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
        Schema::create('printer_poll_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('printer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20)->index();
            $table->string('status', 20)->index();
            $table->string('printer_name')->nullable();
            $table->string('printer_ip', 45)->nullable();
            $table->string('printer_status', 20)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('printer_poll_logs');
    }
};
