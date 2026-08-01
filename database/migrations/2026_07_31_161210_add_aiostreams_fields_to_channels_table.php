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
        Schema::table('channels', function (Blueprint $table) {
            $table->foreignId('aio_integration_id')->nullable()->after('is_custom')
                ->constrained('media_server_integrations')->nullOnDelete();
            $table->string('aio_item_id')->nullable()->after('aio_integration_id');
            $table->string('aio_type')->nullable()->after('aio_item_id');
            $table->string('aio_resolution_status')->nullable()->after('aio_type');
            $table->timestamp('aio_last_resolved_at')->nullable()->after('aio_resolution_status');
            // True for the lightweight sibling Channel rows ResolveAioStreamsChannel creates
            // to represent failover candidates (see ChannelFailover) — these carry the same
            // is_vod/name/title as the real channel purely so the failover pivot can point at
            // a real row, and must stay invisible everywhere channels are listed for actual
            // playback (Xtream API, M3U export, VOD browse) to avoid duplicate-looking movies.
            // Channel queries exclude these via a global scope.
            $table->boolean('is_aio_failover_clone')->default(false)->after('aio_last_resolved_at');

            $table->index(['aio_integration_id', 'aio_item_id', 'aio_type'], 'channels_aio_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropIndex('channels_aio_lookup_index');
            $table->dropConstrainedForeignId('aio_integration_id');
            $table->dropColumn(['aio_item_id', 'aio_type', 'aio_resolution_status', 'aio_last_resolved_at', 'is_aio_failover_clone']);
        });
    }
};
