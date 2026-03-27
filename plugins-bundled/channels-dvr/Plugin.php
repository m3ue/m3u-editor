<?php

namespace AppLocalPlugins\ChannelsDvr;

use App\Models\Channel;
use App\Plugins\Contracts\ChannelProcessorPluginInterface;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use Illuminate\Support\Facades\Http;
use Throwable;

class Plugin implements ChannelProcessorPluginInterface, HookablePluginInterface, PluginInterface
{
    private const STATIONS_PATH = '/dvr/guide/stations';

    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($action) {
            'health_check' => $this->healthCheck($context),
            'sync_station_ids' => $this->syncFromAction($payload, $context),
            default => PluginActionResult::failure("Unsupported action [{$action}]."),
        };
    }

    public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        if ($hook !== 'playlist.synced') {
            return PluginActionResult::success("Hook [{$hook}] not handled by Channels DVR.");
        }

        $playlistId = (int) ($payload['playlist_id'] ?? 0);

        if ($playlistId === 0) {
            return PluginActionResult::failure('Missing playlist_id in hook payload.');
        }

        $configured = $context->settings['default_playlist_id'] ?? null;
        $watchedIds = array_map('intval', array_filter((array) $configured));

        if ($watchedIds === []) {
            return PluginActionResult::success('No default playlist(s) configured — skipping automatic station ID sync.');
        }

        if (! in_array($playlistId, $watchedIds, true)) {
            return PluginActionResult::success("Playlist #{$playlistId} is not in the configured defaults — skipping.");
        }

        return $this->processPlaylist($playlistId, $context);
    }

    private function healthCheck(PluginExecutionContext $context): PluginActionResult
    {
        $baseUrl = $this->buildBaseUrl($context->settings);

        if ($baseUrl === '') {
            return PluginActionResult::failure('DVR host is not configured. Set the DVR Host setting.');
        }

        $url = $baseUrl.self::STATIONS_PATH;
        $context->info("Checking Channels DVR at {$url}...");

        try {
            $response = Http::timeout(10)->get($url);

            if (! $response->successful()) {
                return PluginActionResult::failure("DVR returned HTTP {$response->status()} from {$url}.");
            }

            $stations = $this->parseStations($response->json());

            return PluginActionResult::success('Channels DVR is reachable.', [
                'url' => $url,
                'station_count' => count($stations),
            ]);
        } catch (Throwable $e) {
            return PluginActionResult::failure("Could not reach Channels DVR: {$e->getMessage()}");
        }
    }

    private function syncFromAction(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $playlistId = (int) ($payload['playlist_id'] ?? 0);

        if ($playlistId === 0) {
            return PluginActionResult::failure('Missing playlist_id in action payload.');
        }

        return $this->processPlaylist($playlistId, $context);
    }

    private function processPlaylist(int $playlistId, PluginExecutionContext $context): PluginActionResult
    {
        $settings = $context->settings;
        $baseUrl = $this->buildBaseUrl($settings);
        $overwriteExisting = (bool) ($settings['overwrite_existing'] ?? false);
        $skipVod = (bool) ($settings['skip_vod'] ?? true);
        $isDryRun = $context->dryRun;

        if ($baseUrl === '') {
            return PluginActionResult::failure('DVR host is not configured. Set the DVR Host setting.');
        }

        $url = $baseUrl.self::STATIONS_PATH;
        $context->info("Fetching stations from {$url}...");

        try {
            $response = Http::timeout(30)->get($url);
        } catch (Throwable $e) {
            return PluginActionResult::failure("Failed to connect to Channels DVR: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            return PluginActionResult::failure("Channels DVR returned HTTP {$response->status()} from {$url}.");
        }

        $stations = $this->parseStations($response->json());

        if ($stations === []) {
            return PluginActionResult::success('No stations returned from Channels DVR — nothing to map.');
        }

        $context->info(sprintf('Loaded %d station(s) from Channels DVR.', count($stations)));

        $lookup = $this->buildLookup($stations);

        $query = Channel::query()
            ->where('playlist_id', $playlistId)
            ->where('enabled', true)
            ->select(['id', 'title', 'title_custom', 'name', 'name_custom', 'station_id']);

        if ($skipVod) {
            $query->where('is_vod', false);
        }

        if (! $overwriteExisting) {
            $query->where(function ($q): void {
                $q->whereNull('station_id')->orWhere('station_id', '');
            });
        }

        $channels = $query->get();
        $total = $channels->count();

        if ($total === 0) {
            return PluginActionResult::success('No channels require station ID mapping.', [
                'matched' => 0,
                'unmatched' => 0,
                'total' => 0,
            ]);
        }

        $context->info(sprintf(
            'Processing %d channel(s) for playlist #%d%s.',
            $total,
            $playlistId,
            $isDryRun ? ' [dry run]' : ''
        ));

        $matched = 0;
        $unmatched = 0;

        foreach ($channels as $i => $channel) {
            $displayName = trim((string) ($channel->title_custom ?? $channel->title ?? $channel->name_custom ?? $channel->name ?? ''));

            if ($displayName === '') {
                $unmatched++;

                continue;
            }

            $stationId = $this->resolveStationId($displayName, $lookup);

            if ($stationId !== null) {
                $matched++;
                $context->info("Matched: \"{$displayName}\" → station_id={$stationId}");

                if (! $isDryRun) {
                    Channel::where('id', $channel->id)->update(['station_id' => $stationId]);
                }
            } else {
                $unmatched++;
            }

            if (($i + 1) % 50 === 0) {
                $context->heartbeat(progress: (int) ((($i + 1) / $total) * 100));
            }
        }

        return PluginActionResult::success(
            sprintf(
                '%d of %d channel(s) matched%s.',
                $matched,
                $total,
                $isDryRun ? ' (dry run — no changes written)' : ''
            ),
            [
                'matched' => $matched,
                'unmatched' => $unmatched,
                'total' => $total,
                'dry_run' => $isDryRun,
            ]
        );
    }

    /**
     * Build the base URL from settings (no trailing slash, port appended).
     */
    private function buildBaseUrl(array $settings): string
    {
        $host = trim((string) ($settings['dvr_host'] ?? ''));

        if ($host === '') {
            return '';
        }

        if (! str_starts_with($host, 'http://') && ! str_starts_with($host, 'https://')) {
            $host = 'http://'.$host;
        }

        $port = (int) ($settings['dvr_port'] ?? 8089);

        return rtrim($host, '/').":{$port}";
    }

    /**
     * Parse the raw Channels DVR guide stations response into a flat list.
     *
     * The actual API response is a nested map:
     *   { "<provider>": { "<stationId>": { "callSign": "...", "stationId": "...", ... } } }
     *
     * Multiple providers may be present at the top level. Each station object
     * is keyed by its numeric station ID and contains `callSign` and `stationId`.
     * The `name` field is typically empty — call sign is the primary identifier.
     *
     * @return array<int, array{station_id: string, name: string, callsign: string}>
     */
    private function parseStations(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $stations = [];

        foreach ($raw as $providerStations) {
            if (! is_array($providerStations)) {
                continue;
            }

            foreach ($providerStations as $stationKey => $station) {
                if (! is_array($station)) {
                    continue;
                }

                $id = (string) ($station['stationId'] ?? $station['StationID'] ?? $station['station_id'] ?? $stationKey ?? '');
                $name = (string) ($station['name'] ?? $station['Name'] ?? '');
                $callsign = (string) ($station['callSign'] ?? $station['CallSign'] ?? $station['callsign'] ?? '');

                if ($id === '') {
                    continue;
                }

                $stations[] = ['station_id' => $id, 'name' => $name, 'callsign' => $callsign];
            }
        }

        return $stations;
    }

    /**
     * Build a normalized lookup map: normalized_label → station_id.
     *
     * Call sign is indexed first so it wins over the full name when both
     * normalize to the same string (e.g. "ESPN" callsign vs "ESPN" name).
     *
     * @param  array<int, array{station_id: string, name: string, callsign: string}>  $stations
     * @return array<string, string>
     */
    private function buildLookup(array $stations): array
    {
        $lookup = [];

        foreach ($stations as $station) {
            foreach ([$station['callsign'], $station['name']] as $label) {
                $key = $this->normalize($label);

                if ($key !== '' && ! isset($lookup[$key])) {
                    $lookup[$key] = $station['station_id'];
                }
            }
        }

        return $lookup;
    }

    /**
     * Attempt to resolve a station ID for a channel display name.
     *
     * Tries in order:
     *   1. Exact normalized match (e.g. "ESPN" → "espn")
     *   2. Strip common quality suffixes and retry (e.g. "ESPN HD" → "ESPN")
     *
     * @param  array<string, string>  $lookup
     */
    private function resolveStationId(string $displayName, array $lookup): ?string
    {
        $key = $this->normalize($displayName);

        if (isset($lookup[$key])) {
            return $lookup[$key];
        }

        // Strip trailing quality/resolution suffixes and retry
        $stripped = preg_replace('/\s+(HD|SD|FHD|UHD|4K|\d+p)$/i', '', $displayName);

        if ($stripped !== null && $stripped !== $displayName) {
            $key = $this->normalize($stripped);

            if (isset($lookup[$key])) {
                return $lookup[$key];
            }
        }

        return null;
    }

    /**
     * Normalize a station label to a plain lowercase alphanumeric string
     * for case- and punctuation-insensitive matching.
     */
    private function normalize(string $value): string
    {
        return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '', $value)));
    }
}
