<?php

namespace AppLocalPlugins\TvLogos;

use App\Models\Channel;
use App\Plugins\Contracts\ChannelProcessorPluginInterface;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class Plugin implements ChannelProcessorPluginInterface, HookablePluginInterface, PluginInterface
{
    private const CDN_BASE = 'https://cdn.jsdelivr.net/gh/tv-logo/tv-logos@main/countries';

    private const INDEX_API_BASE = 'https://api.github.com/repos/tv-logo/tv-logos/contents/countries';

    private const CACHE_FILE = 'plugin-data/tv-logos/matches.json';

    /**
     * Maps ISO 3166-1 alpha-2 country codes to their folder names in the tv-logo/tv-logos repo.
     *
     * @var array<string, string>
     */
    private const COUNTRY_FOLDERS = [
        'al' => 'albania',
        'dz' => 'algeria',
        'ar' => 'argentina',
        'au' => 'australia',
        'at' => 'austria',
        'be' => 'belgium',
        'ba' => 'bosnia-and-herzegovina',
        'br' => 'brazil',
        'bg' => 'bulgaria',
        'ca' => 'canada',
        'cn' => 'china',
        'hr' => 'croatia',
        'cz' => 'czech-republic',
        'dk' => 'denmark',
        'eg' => 'egypt',
        'ee' => 'estonia',
        'fi' => 'finland',
        'fr' => 'france',
        'de' => 'germany',
        'gr' => 'greece',
        'hu' => 'hungary',
        'in' => 'india',
        'ie' => 'ireland',
        'is' => 'iceland',
        'il' => 'israel',
        'it' => 'italy',
        'jp' => 'japan',
        'xk' => 'kosovo',
        'lv' => 'latvia',
        'lt' => 'lithuania',
        'lu' => 'luxembourg',
        'mk' => 'north-macedonia',
        'me' => 'montenegro',
        'mx' => 'mexico',
        'nl' => 'netherlands',
        'nz' => 'new-zealand',
        'ng' => 'nigeria',
        'no' => 'norway',
        'pl' => 'poland',
        'pt' => 'portugal',
        'ro' => 'romania',
        'ru' => 'russia',
        'sa' => 'saudi-arabia',
        'rs' => 'serbia',
        'sk' => 'slovakia',
        'si' => 'slovenia',
        'za' => 'south-africa',
        'kr' => 'south-korea',
        'es' => 'spain',
        'se' => 'sweden',
        'ch' => 'switzerland',
        'tr' => 'turkey',
        'ua' => 'ukraine',
        'ae' => 'united-arab-emirates',
        'gb' => 'united-kingdom',
        'us' => 'united-states',
    ];

    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($action) {
            'health_check' => $this->healthCheck($context),
            'enrich_logos' => $this->enrichFromAction($payload, $context),
            default => PluginActionResult::failure("Unsupported action [{$action}]."),
        };
    }

    public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        if ($hook !== 'playlist.synced') {
            return PluginActionResult::success("Hook [{$hook}] not handled by TV Logos.");
        }

        $playlistId = (int) ($payload['playlist_id'] ?? 0);

        if ($playlistId === 0) {
            return PluginActionResult::failure('Missing playlist_id in hook payload.');
        }

        $configured = $context->settings['default_playlist_id'] ?? null;
        $watchedIds = array_map('intval', array_filter((array) $configured));

        if ($watchedIds === []) {
            return PluginActionResult::success('No default playlist(s) configured — skipping automatic enrichment.');
        }

        if (! in_array($playlistId, $watchedIds, true)) {
            return PluginActionResult::success("Playlist #{$playlistId} is not in the configured defaults — skipping.");
        }

        return $this->processPlaylist($playlistId, $context);
    }

    /**
     * Ping the CDN and report cache stats.
     */
    private function healthCheck(PluginExecutionContext $context): PluginActionResult
    {
        $context->info('Checking tv-logos CDN reachability...');

        $reachable = false;

        try {
            $response = Http::timeout(10)->head(self::CDN_BASE.'/united-states/espn-us.png');
            $reachable = $response->successful();
        } catch (Throwable) {
            // CDN unreachable
        }

        $cacheEntries = 0;

        try {
            $cache = $this->loadCache(0);
            $cacheEntries = count($cache['matches'] ?? []);
        } catch (Throwable) {
            // Cache unreadable
        }

        return PluginActionResult::success('Health check complete.', [
            'cdn_reachable' => $reachable,
            'cdn_base' => self::CDN_BASE,
            'cached_entries' => $cacheEntries,
            'supported_countries' => array_keys(self::COUNTRY_FOLDERS),
        ]);
    }

    /**
     * Entry point for the manual enrich_logos action.
     */
    private function enrichFromAction(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $playlistId = (int) ($payload['playlist_id'] ?? 0);

        if ($playlistId === 0) {
            return PluginActionResult::failure('Missing playlist_id in action payload.');
        }

        return $this->processPlaylist($playlistId, $context);
    }

    /**
     * Core enrichment logic — queries channels for the given playlist and attempts
     * to match each one against a logo from the tv-logo/tv-logos CDN.
     */
    private function processPlaylist(int $playlistId, PluginExecutionContext $context): PluginActionResult
    {
        $settings = $context->settings;
        $countryCode = strtolower(trim((string) ($settings['country_code'] ?? 'us')));
        $overwriteExisting = (bool) ($settings['overwrite_existing'] ?? false);
        $skipVod = (bool) ($settings['skip_vod'] ?? true);
        $cacheTtlDays = (int) ($settings['cache_ttl_days'] ?? 7);
        $isDryRun = $context->dryRun;

        $countryFolder = self::COUNTRY_FOLDERS[$countryCode] ?? null;

        if ($countryFolder === null) {
            return PluginActionResult::failure(sprintf(
                'Unknown country code [%s]. Supported codes: %s.',
                $countryCode,
                implode(', ', array_keys(self::COUNTRY_FOLDERS))
            ));
        }

        $cache = $this->loadCache($cacheTtlDays);

        $cacheChanged = false;
        $index = $this->fetchCountryIndex($countryCode, $countryFolder, $cache, $cacheChanged);

        if ($index !== []) {
            $context->info(sprintf('Loaded index of %d known logos for %s.', count($index), $countryFolder));
        } else {
            $context->info('Logo index unavailable — falling back to per-channel CDN HEAD checks (slower).');
        }

        $query = Channel::query()
            ->where('playlist_id', $playlistId)
            ->where('enabled', true)
            ->select(['id', 'title', 'title_custom', 'name', 'name_custom', 'logo']);

        if ($skipVod) {
            $query->where('is_vod', false);
        }

        if (! $overwriteExisting) {
            $query->where(function ($q): void {
                $q->whereNull('logo')->orWhere('logo', '');
            });
        }

        $channels = $query->get();
        $total = $channels->count();

        if ($total === 0) {
            return PluginActionResult::success('No channels require logo enrichment.', [
                'matched' => 0,
                'skipped' => 0,
                'total' => 0,
            ]);
        }

        $context->info(sprintf(
            'Processing %d channel(s) for playlist #%d [country=%s%s].',
            $total,
            $playlistId,
            $countryCode,
            $isDryRun ? ', dry_run' : ''
        ));

        $matched = 0;
        $cacheHits = 0;
        $cacheMisses = 0;

        foreach ($channels as $i => $channel) {
            $displayName = trim((string) ($channel->title_custom ?? $channel->title ?? $channel->name_custom ?? $channel->name ?? ''));

            if ($displayName === '') {
                continue;
            }

            $cacheKey = $countryCode.':'.mb_strtolower($displayName, 'UTF-8');

            if (array_key_exists($cacheKey, $cache['matches'])) {
                $logoUrl = $cache['matches'][$cacheKey] ?: null;
                $cacheHits++;
            } else {
                $logoUrl = $this->resolveLogoUrl($displayName, $countryCode, $countryFolder, $index);
                $cache['matches'][$cacheKey] = $logoUrl ?? '';
                $cacheChanged = true;
                $cacheMisses++;
            }

            if ($logoUrl !== null) {
                $matched++;
                $context->info("Matched: \"{$displayName}\" → {$logoUrl}");

                if (! $isDryRun) {
                    Channel::where('id', $channel->id)->update(['logo' => $logoUrl]);
                }
            }

            if (($i + 1) % 20 === 0) {
                $context->heartbeat(progress: (int) ((($i + 1) / $total) * 100));
            }
        }

        if ($cacheChanged && ! $isDryRun) {
            $this->saveCache($cache);
        }

        return PluginActionResult::success(
            sprintf('%d of %d channel(s) matched%s.', $matched, $total, $isDryRun ? ' (dry run — no changes written)' : ''),
            [
                'matched' => $matched,
                'total' => $total,
                'cache_hits' => $cacheHits,
                'cache_misses' => $cacheMisses,
                'country_code' => $countryCode,
                'dry_run' => $isDryRun,
            ]
        );
    }

    /**
     * Attempt to resolve a CDN logo URL for the given channel name.
     *
     * When an index is available (fetched once per run from the GitHub Contents
     * API), resolution is a pure O(1) array lookup — no HTTP requests per channel.
     * Falls back to sequential CDN HEAD checks only when the index is unavailable.
     *
     * Tries candidate slugs with and without quality tokens (when present), then
     * probes country root and subfolders (for example `hd/`) in a quality-aware
     * order so channels like "Das Erste HD" can resolve to HD-specific assets.
     *
     * @param  array<string, true>  $index  Filename → true map; empty array triggers HEAD fallback.
     */
    private function resolveLogoUrl(string $channelName, string $countryCode, string $countryFolder, array $index): ?string
    {
        $slugs = array_values(array_unique(array_filter([
            $this->slugify($channelName, false),
            $this->slugify($channelName, true),
        ])));

        if ($slugs === []) {
            return null;
        }

        $qualityFolders = $this->preferredQualityFolders($channelName);

        $filenames = [];

        foreach ($slugs as $slug) {
            $filenames[] = "{$slug}-{$countryCode}.png";
            $filenames[] = "{$slug}.png";

            $parts = explode('-', $slug);
            if (count($parts) > 1) {
                $shortened = implode('-', array_slice($parts, 0, -1));
                if ($shortened !== '') {
                    $filenames[] = "{$shortened}-{$countryCode}.png";
                }
            }
        }

        $filenames = array_values(array_unique($filenames));

        foreach ($qualityFolders as $folder) {
            foreach ($filenames as $filename) {
                $relativePath = $folder === '' ? $filename : "{$folder}/{$filename}";
                $url = self::CDN_BASE."/{$countryFolder}/{$relativePath}";

                $exists = $index !== []
                    ? isset($index[strtolower($relativePath)])
                    : $this->urlExists($url);

                if ($exists) {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function preferredQualityFolders(string $channelName): array
    {
        $hasHdHint = (bool) preg_match('/\b(hd|fhd|uhd|4k|8k|1080[pi]|720p)\b/iu', $channelName);

        return $hasHdHint ? ['hd', ''] : ['', 'hd'];
    }

    /**
     * Fetch the set of known logo filenames for a country folder from the
     * GitHub Contents API and store it in the cache.
     *
     * Returns a map of lowercase filename → true for O(1) lookups.
     * Returns an empty array on failure so callers can fall back to HEAD checks.
     *
     * @param  array<string, mixed>  $cache
     * @return array<string, true>
     */
    private function fetchCountryIndex(string $countryCode, string $countryFolder, array &$cache, bool &$cacheChanged): array
    {
        $cacheKey = "index:{$countryCode}";

        if (array_key_exists($cacheKey, $cache) && is_array($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'tv-logos-plugin/1.0',
                ])
                ->get(self::INDEX_API_BASE.'/'.$countryFolder);

            if ($response->successful()) {
                $index = $this->collectPngIndexEntries($countryFolder);

                $cache[$cacheKey] = $index;
                $cacheChanged = true;

                return $index;
            }
        } catch (Throwable) {
            // Index unavailable — fall back to per-channel HEAD checks.
        }

        return [];
    }

    /**
     * Recursively collect PNG logo files from a country folder and its subfolders.
     *
     * @return array<string, true>
     */
    private function collectPngIndexEntries(string $path, string $prefix = ''): array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'tv-logos-plugin/1.0',
                ])
                ->get(self::INDEX_API_BASE.'/'.$path);

            if (! $response->successful()) {
                return [];
            }

            $entries = [];

            foreach ((array) ($response->json() ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $type = (string) ($item['type'] ?? '');
                $name = (string) ($item['name'] ?? '');

                if ($type === 'file' && str_ends_with($name, '.png')) {
                    $entries[strtolower($prefix.$name)] = true;

                    continue;
                }

                if ($type === 'dir' && $name !== '') {
                    $childPath = trim($path.'/'.$name, '/');
                    $childPrefix = $prefix.$name.'/';
                    $entries = [...$entries, ...$this->collectPngIndexEntries($childPath, $childPrefix)];
                }
            }

            return $entries;
        } catch (Throwable) {
            return [];
        }
    }

    private function urlExists(string $url): bool
    {
        try {
            return Http::timeout(8)->head($url)->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Normalise a channel name into a hyphenated slug suitable for tv-logo filenames.
     *
     * Steps: lowercase → strip quality tags and bracket content → normalise & → strip
     * non-alphanumeric → collapse whitespace → hyphenate.
     */
    private function slugify(string $name, bool $stripQualityTags = true): string
    {
        // Split camelCase / PascalCase boundaries BEFORE lowercasing
        // e.g. "ProSieben" → "Pro Sieben", "SportDeutschland" → "Sport Deutschland"
        $name = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $name) ?? $name;

        $name = mb_strtolower($name, 'UTF-8');

        if ($stripQualityTags) {
            // Strip quality suffixes (hd, fhd, 4k, etc.)
            $name = preg_replace('/\b(hd|fhd|uhd|4k|8k|sd|1080[pi]|720p|hevc|h\.?264|h\.?265)\b/iu', '', $name) ?? $name;
        }

        // Remove content inside any bracket type
        $name = preg_replace('/[\(\[\{][^\)\]\}]*[\)\]\}]/', '', $name) ?? $name;

        // Normalise ampersand
        $name = str_replace('&', 'and', $name);

        // Keep only unicode letters, digits, and spaces
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name) ?? $name;

        // Collapse whitespace
        $name = preg_replace('/\s+/', ' ', trim($name)) ?? $name;

        // Hyphenate and collapse consecutive hyphens
        $name = str_replace(' ', '-', $name);
        $name = preg_replace('/-+/', '-', $name) ?? $name;

        return trim($name, '-');
    }

    /**
     * Load the match cache from storage.
     *
     * Returns an empty cache structure when the file is missing, malformed, or expired.
     *
     * @return array{version: int, cached_at: string, matches: array<string, string>}
     */
    private function loadCache(int $cacheTtlDays): array
    {
        $empty = ['version' => 3, 'cached_at' => now()->toIso8601String(), 'matches' => []];

        try {
            if (! Storage::disk('local')->exists(self::CACHE_FILE)) {
                return $empty;
            }

            $data = json_decode((string) Storage::disk('local')->get(self::CACHE_FILE), true);

            if (! is_array($data) || ! isset($data['matches']) || ($data['version'] ?? 1) < 3) {
                return $empty;
            }

            if ($cacheTtlDays > 0 && isset($data['cached_at'])) {
                if (Carbon::parse($data['cached_at'])->diffInDays(now()) >= $cacheTtlDays) {
                    return $empty;
                }
            }

            return $data;
        } catch (Throwable) {
            return $empty;
        }
    }

    /**
     * Persist the match cache to storage.
     */
    private function saveCache(array $cache): void
    {
        try {
            Storage::disk('local')->put(
                self::CACHE_FILE,
                json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        } catch (Throwable) {
            // Non-fatal — a missing cache means the next run re-checks the CDN.
        }
    }
}
