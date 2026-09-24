<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Alias entries now separate the provider URL (which streams an entry applies to)
     * from the replacement URL (what clients receive). Standard aliases of Xtream
     * playlists whose entry URL is not one of the playlist's own URLs were relying on
     * the old implicit fallback to send clients to that URL. Move it into the new
     * replacement fields and point the provider URL at the playlist, so the form shows
     * what actually happens. Stream URLs produced are identical before and after.
     */
    public function up(): void
    {
        DB::table('playlist_aliases')
            ->whereNotNull('playlist_id')
            ->whereNotNull('xtream_config')
            ->select(['id', 'playlist_id', 'xtream_config'])
            ->chunkById(100, function ($aliases): void {
                foreach ($aliases as $alias) {
                    $entries = json_decode((string) $alias->xtream_config, true);
                    if (! is_array($entries)) {
                        continue;
                    }

                    // Legacy format: a single entry stored as an object.
                    if (array_key_exists('url', $entries)) {
                        $entries = [$entries];
                    }

                    if (count($entries) !== 1 || ! is_array($entries[0] ?? null)) {
                        continue;
                    }

                    $entry = $entries[0];
                    $entryUrl = rtrim((string) ($entry['url'] ?? ''), '/');
                    if ($entryUrl === '' || ! empty($entry['replace_url_enabled'])) {
                        continue;
                    }

                    $playlist = DB::table('playlists')
                        ->where('id', $alias->playlist_id)
                        ->first(['xtream_config', 'xtream_fallback_urls']);

                    $playlistConfig = json_decode((string) ($playlist->xtream_config ?? ''), true);
                    $sourceUrl = rtrim((string) ($playlistConfig['url'] ?? ''), '/');
                    if ($sourceUrl === '') {
                        continue;
                    }

                    // URLs the playlist itself uses (primary and DNS fallbacks) are left as
                    // they are, so aliases that follow DNS failover keep doing so.
                    $playlistUrls = array_map(
                        fn ($url) => strtolower(rtrim((string) $url, '/')),
                        [$sourceUrl, ...(json_decode((string) ($playlist->xtream_fallback_urls ?? ''), true) ?: [])]
                    );
                    if (in_array(strtolower($entryUrl), $playlistUrls, true)) {
                        continue;
                    }

                    $entry['url'] = $sourceUrl;
                    $entry['replace_url_enabled'] = true;
                    $entry['replace_url'] = $entryUrl;

                    DB::table('playlist_aliases')
                        ->where('id', $alias->id)
                        ->update(['xtream_config' => json_encode([$entry])]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only Xtream playlist aliases, where the old fallback reproduces the same URLs.
        DB::table('playlist_aliases')
            ->whereNotNull('playlist_id')
            ->whereNotNull('xtream_config')
            ->whereIn('playlist_id', DB::table('playlists')->whereNotNull('xtream_config')->select('id'))
            ->select(['id', 'xtream_config'])
            ->chunkById(100, function ($aliases): void {
                foreach ($aliases as $alias) {
                    $entries = json_decode((string) $alias->xtream_config, true);
                    if (! is_array($entries) || count($entries) !== 1 || ! is_array($entries[0] ?? null)) {
                        continue;
                    }

                    $entry = $entries[0];
                    if (empty($entry['replace_url_enabled']) || empty($entry['replace_url'])
                        || empty($entry['username']) || empty($entry['password'])) {
                        continue;
                    }

                    $entry['url'] = rtrim((string) $entry['replace_url'], '/');
                    unset($entry['replace_url_enabled'], $entry['replace_url']);

                    DB::table('playlist_aliases')
                        ->where('id', $alias->id)
                        ->update(['xtream_config' => json_encode([$entry])]);
                }
            });
    }
};
