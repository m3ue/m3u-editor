<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Diagnostic log snapshots uploaded from the M3U TV app's Logs &
     * Diagnostics screen, viewable from the Registered Devices list. Only the
     * most recent few per device are kept (see TvDeviceLog::KEEP_PER_DEVICE)
     * and old ones are pruned.
     */
    public function up(): void
    {
        Schema::create('tv_device_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tv_device_id')->constrained()->cascadeOnDelete();
            $table->string('app_version')->nullable();
            $table->unsignedInteger('size_bytes');
            $table->longText('content');
            $table->timestamps();

            $table->index(['tv_device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_device_logs');
    }
};
