<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        DB::table('push_device_tokens')
            ->select('token')
            ->groupBy('token')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('token')
            ->each(function (object $duplicate): void {
                $keeper = DB::table('push_device_tokens')
                    ->where('token', $duplicate->token)
                    ->orderByDesc('last_seen_at')
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->first();

                if ($keeper === null) {
                    return;
                }

                DB::table('push_device_tokens')
                    ->where('token', $duplicate->token)
                    ->where('id', '!=', $keeper->id)
                    ->delete();
            });

        Schema::table('push_device_tokens', function (Blueprint $table) {
            $table->dropUnique('push_device_tokens_notifiable_token_unique');
            $table->unique('token', 'push_device_tokens_token_unique');
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
            $table->dropUnique('push_device_tokens_token_unique');
            $table->unique(['notifiable_type', 'notifiable_id', 'token'], 'push_device_tokens_notifiable_token_unique');
            $table->dropForeign(['playlist_auth_id']);
            $table->dropColumn('playlist_auth_id');
        });
    }
};
