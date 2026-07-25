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
        Schema::table('tv_notifications', function (Blueprint $table) {
            $table->foreignId('playlist_auth_id')
                ->nullable()
                ->after('channel')
                ->constrained('playlist_auths')
                ->cascadeOnDelete();
            $table->json('metadata')->nullable()->after('playlist_auth_id');
        });

        Schema::table('push_device_tokens', function (Blueprint $table) {
            $table->foreignId('playlist_auth_id')
                ->nullable()
                ->after('notifiable_id')
                ->constrained('playlist_auths')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tv_notifications', function (Blueprint $table) {
            $table->dropForeign(['playlist_auth_id']);
            $table->dropColumn(['playlist_auth_id', 'metadata']);
        });

        Schema::table('push_device_tokens', function (Blueprint $table) {
            $table->dropForeign(['playlist_auth_id']);
            $table->dropColumn('playlist_auth_id');
        });
    }
};
