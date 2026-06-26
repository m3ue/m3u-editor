<?php

use App\Models\Channel;
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
        // Make sure the column does not already exist
        if (! Schema::hasColumn('channels', 'source_id')) {
            Schema::table('channels', function (Blueprint $table) {
                $table->string('source_id')->nullable()->after('stream_id');
            });
        }

        // Update existing channels to set source_id to their stream_id
        // The `url` variable will contain the stream ID in the last path, minus the extension
        // E.g., "https://example.com/stream/12345.m3u8" will set source_id to "12345"
        // This assumes that the URL is well-formed and contains a stream ID at the end

        // Process channels in smaller batches to avoid memory issues
        Channel::whereNotNull('url')
            ->chunkById(100, function ($channels) {
                foreach ($channels as $channel) {
                    // Strip the query string first so pathinfo only sees the path segment.
                    // Without this, URLs like ".../master.m3u8?very=long&query=string" produce
                    // a source_id longer than varchar(255), which fails on Postgres.
                    $urlPath = parse_url($channel->url, PHP_URL_PATH) ?? $channel->url;
                    $urlParts = explode('/', $urlPath);
                    $streamIdWithExtension = end($urlParts);
                    $streamId = substr(pathinfo($streamIdWithExtension, PATHINFO_FILENAME), 0, 255);

                    // Use DB::table for direct update to avoid model events and potential issues
                    DB::table('channels')
                        ->where('id', $channel->id)
                        ->update(['source_id' => $streamId]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('source_id');
        });
    }
};
