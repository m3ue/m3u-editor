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
        Schema::create('tv_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tv_notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playlist_auth_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['tv_notification_id', 'playlist_auth_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tv_notification_reads');
    }
};
