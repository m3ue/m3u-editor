<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_request_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        // Backfill: any playlist that already had an arr_integration pointing at it
        // gets a request setting with enabled = true so existing behaviour is preserved.
        $rows = DB::table('arr_integrations')
            ->select('playlist_id', 'user_id')
            ->whereNotNull('playlist_id')
            ->distinct()
            ->get();

        foreach ($rows as $row) {
            DB::table('playlist_request_settings')->insertOrIgnore([
                'playlist_id' => $row->playlist_id,
                'user_id' => $row->user_id,
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Drop the playlist_id FK, index, and column from arr_integrations.
        Schema::table('arr_integrations', function (Blueprint $table) {
            $table->dropForeign(['playlist_id']);
            $table->dropIndex(['playlist_id', 'enabled', 'guest_enabled']);
            $table->dropColumn('playlist_id');
        });
    }

    public function down(): void
    {
        Schema::table('arr_integrations', function (Blueprint $table) {
            $table->foreignId('playlist_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index(['playlist_id', 'enabled', 'guest_enabled']);
        });

        Schema::dropIfExists('playlist_request_settings');
    }
};
