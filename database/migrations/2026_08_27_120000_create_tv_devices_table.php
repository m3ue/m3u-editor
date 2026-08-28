<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unified registry of M3U TV app installs across every platform (Android /
     * Android TV / iOS / tvOS / desktop). Rows are upserted from the boot/resume
     * `GET /api/tv/{username}/{password}/notifications` call the app already
     * makes, so there is no dedicated heartbeat. `last_seen_at` doubles as
     * "last successfully talked to the server".
     */
    public function up(): void
    {
        Schema::create('tv_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_id')->unique();
            $table->nullableMorphs('notifiable');
            $table->foreignId('playlist_auth_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_name')->nullable();
            $table->string('platform')->nullable();
            $table->string('app_version')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // nullableMorphs() already indexes (notifiable_type, notifiable_id).
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_devices');
    }
};
