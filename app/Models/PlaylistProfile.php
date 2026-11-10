<?php

namespace App\Models;

use App\Enums\DefaultAuthMode;
use App\Services\PlaylistCredentialResolver;
use App\Services\PlaylistUrlService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PlaylistProfile extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'playlist_id' => 'integer',
        'user_id' => 'integer',
        'max_streams' => 'integer',
        'priority' => 'integer',
        'enabled' => 'boolean',
        'is_primary' => 'boolean',
        'provider_info' => 'array',
        'provider_info_updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Get the playlist that owns this profile.
     */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    /**
     * Get the user that owns this profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Build xtream_config array compatible with XtreamService.
     *
     * Uses this profile's URL if set, otherwise falls back to playlist's base URL.
     * This allows profiles to connect to different providers.
     */
    public function getXtreamConfigAttribute(): ?array
    {
        if (! $this->playlist || ! $this->playlist->xtream_config) {
            return null;
        }

        $baseConfig = $this->playlist->xtream_config;

        // Use profile's URL if set, otherwise use playlist's URL
        $url = $this->url ?? $baseConfig['url'] ?? $baseConfig['server'] ?? null;

        if (! $url) {
            return null;
        }

        return [
            // Use 'url' key to match XtreamService::init() expectations
            'url' => $url,
            'username' => $this->username,
            'password' => $this->password,
            'output' => $baseConfig['output'] ?? 'ts',
        ];
    }

    /**
     * Get provider info with caching.
     * PERFORMANCE FIX: Only returns stored/cached data, never makes HTTP calls during page render.
     * Use ProfileService::refreshProfile() or background jobs to update provider info.
     */
    public function providerInfo(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $cacheKey = "playlist_profile:{$attributes['id']}:provider_info";

                // Try to get from cache first
                $cached = Cache::get($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }

                // Return stored value from database (never fetch live during page render)
                $result = is_string($value) ? json_decode($value, true) : ($value ?? []);

                // Cache the database value for 60 seconds
                if ($result) {
                    Cache::put($cacheKey, $result, 60);
                }

                return $result;
            }
        );
    }

    /**
     * Get the current connection count from provider info.
     */
    public function getCurrentConnectionsAttribute(): int
    {
        $info = $this->provider_info;

        return (int) ($info['user_info']['active_cons'] ?? 0);
    }

    /**
     * Get the max connections allowed by the provider.
     *
     * Returns PHP_INT_MAX when provider_info has not been fetched yet,
     * so that effective_max_streams uses the user's explicit max_streams
     * without being artificially capped to 1.
     */
    public function getProviderMaxConnectionsAttribute(): int
    {
        $info = $this->provider_info;

        // If provider info hasn't been fetched yet, don't restrict
        // the user's configured max_streams with an artificial cap of 1.
        if (empty($info) || ! isset($info['user_info']['max_connections'])) {
            return PHP_INT_MAX;
        }

        return (int) $info['user_info']['max_connections'];
    }

    /**
     * Get the effective max streams (user-defined or provider-defined).
     */
    public function getEffectiveMaxStreamsAttribute(): int
    {
        $providerMax = $this->provider_max_connections;

        // Use user-defined max_streams if set, capped by provider's limit
        if ($this->max_streams && $this->max_streams > 0) {
            return $providerMax === PHP_INT_MAX
                ? $this->max_streams
                : min($this->max_streams, $providerMax);
        }

        // No user-defined limit: use provider's limit, or fall back to 1
        return $providerMax === PHP_INT_MAX ? 1 : $providerMax;
    }

    /**
     * Get available stream slots.
     */
    public function getAvailableStreamsAttribute(): int
    {
        return max(0, $this->effective_max_streams - $this->current_connections);
    }

    /**
     * Check if this profile has available capacity.
     */
    public function hasCapacity(): bool
    {
        return $this->enabled && $this->available_streams > 0;
    }

    /**
     * Scope to only enabled profiles.
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to order by selection priority (priority ASC, then by available capacity DESC).
     */
    public function scopeOrderBySelection($query)
    {
        return $query->orderBy('priority')->orderBy('id');
    }

    /**
     * Scope to get only profiles with capacity.
     * Note: This checks the provider info, so it may require fresh data.
     */
    public function scopeWithCapacity($query)
    {
        return $query->enabled()->orderBySelection();
    }

    /**
     * Get the primary profile for a playlist.
     */
    public static function getPrimaryForPlaylist(int $playlistId): ?self
    {
        return static::where('playlist_id', $playlistId)
            ->where('is_primary', true)
            ->first();
    }

    /**
     * Select the best available profile for streaming.
     *
     * @param  int  $playlistId  The playlist ID
     * @param  int|null  $excludeProfileId  Optional profile ID to exclude (for failover)
     */
    public static function selectForStreaming(int $playlistId, ?int $excludeProfileId = null): ?self
    {
        $query = static::where('playlist_id', $playlistId)
            ->enabled()
            ->orderBySelection();

        if ($excludeProfileId) {
            $query->where('id', '!=', $excludeProfileId);
        }

        // Get all profiles and check capacity
        $profiles = $query->get();

        foreach ($profiles as $profile) {
            if ($profile->hasCapacity()) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * Transform a channel URL to use this profile's credentials.
     */
    public function transformChannelUrl(Channel $channel): string
    {
        if ($resolved = $this->resolveInternalUrl($channel)) {
            return $resolved;
        }

        $originalUrl = $channel->url_custom ?? $channel->url ?? '';

        return $this->transformUrl($originalUrl);
    }

    /**
     * Transform an episode URL to use this profile's credentials.
     */
    public function transformEpisodeUrl(Episode $episode): string
    {
        if ($resolved = $this->resolveInternalUrl($episode)) {
            return $resolved;
        }

        $originalUrl = $episode->url ?? '';

        return $this->transformUrl($originalUrl);
    }

    /**
     * When this profile's URL points at another playlist hosted by THIS
     * m3u-editor instance (e.g. a user importing a provider as a plain M3U
     * playlist and then using that playlist's own auto-generated Xtream
     * credentials as a "secondary" profile), resolve directly to the
     * matching channel/episode on the target playlist and return its real
     * upstream URL.
     *
     * Without this, the generic transformUrl() string-swap would send the
     * request back through this app's own Xtream stream controller with the
     * SOURCE playlist's channel/episode PK, which the TARGET playlist has no
     * way to resolve (PKs are playlist-local) - an extra hop that also
     * requires PK-to-PK translation via source_id.
     *
     * Returns null for the common case (profile holds direct remote
     * provider credentials, or a genuinely different, non-local provider
     * URL), leaving the existing string-swap path completely unaffected.
     */
    private function resolveInternalUrl(Channel|Episode $model): ?string
    {
        if (! $this->url || ! str_starts_with(rtrim($this->url, '/'), rtrim(url('/'), '/'))) {
            return null;
        }

        $isEpisode = $model instanceof Episode;

        // Channel's provider-native ID column is `source_id`; Episode's is
        // `source_episode_id` - they are not interchangeable columns.
        $sourceId = $isEpisode ? $model->source_episode_id : $model->source_id;
        if (! $sourceId) {
            return null;
        }

        // The target's default login: its UUID while UUID login is on (matched on the
        // password alone, as before), otherwise its owner's name + custom password.
        $targetPlaylist = Playlist::where('uuid', $this->password)
            ->where('default_auth_mode', DefaultAuthMode::Uuid)
            ->first()
            ?? app(PlaylistCredentialResolver::class)->resolveDefaultLogin((string) $this->username, (string) $this->password, [Playlist::class]);
        if (! $targetPlaylist || $targetPlaylist->id === $this->playlist_id) {
            return null;
        }

        $targetModel = $isEpisode
            ? Episode::where('playlist_id', $targetPlaylist->id)
                ->where('source_episode_id', $sourceId)
                ->where('enabled', true)
                ->first()
            : $targetPlaylist->channels()
                ->where('source_id', $sourceId)
                ->where('is_vod', (bool) $model->is_vod)
                ->where('enabled', true)
                ->first();

        // Fallback for 2-step setups: when the source playlist was imported via
        // Xtream (numeric source_id) but the target playlist was imported as a
        // plain M3U (hash-based source_id), the ids never match. Resolve the
        // channel by exact name instead, using the `group` string as a
        // tie-breaker. Skip when still ambiguous rather than guess wrong.
        if (! $targetModel && ! $isEpisode && $model->name) {
            $candidates = $targetPlaylist->channels()
                ->where('enabled', true)
                ->where('is_vod', (bool) $model->is_vod)
                ->where('name', $model->name)
                ->get();

            if ($candidates->count() === 1) {
                $targetModel = $candidates->first();
            } elseif ($candidates->count() > 1 && $model->group) {
                $targetModel = $candidates->firstWhere('group', $model->group);
            }
        }

        if (! $targetModel) {
            Log::warning('Could not resolve internal profile URL to target playlist', [
                'profile_id' => $this->id,
                'model_type' => $isEpisode ? 'episode' : 'channel',
                'source_id' => $sourceId,
                'target_playlist_id' => $targetPlaylist->id,
            ]);

            return null;
        }

        return $isEpisode
            ? PlaylistUrlService::getEpisodeUrl($targetModel, $targetPlaylist)
            : PlaylistUrlService::getChannelUrl($targetModel, $targetPlaylist);
    }

    /**
     * Transform a URL to use this profile's credentials.
     *
     * Replaces the playlist's primary credentials with this profile's credentials.
     * If the profile has a custom URL, the entire base URL is replaced as well.
     *
     * Xtream playlists swap the credentials stored in xtream_config; M3U playlists
     * have no stored provider config and go through transformM3uUrl() instead.
     *
     * Only reached for direct-to-provider profiles; profiles pointing at a
     * locally-hosted playlist are resolved by resolveInternalUrl() before this
     * is ever called (see transformChannelUrl()/transformEpisodeUrl()).
     */
    public function transformUrl(string $originalUrl): string
    {
        $playlist = $this->playlist;

        if (! $playlist) {
            return $originalUrl;
        }

        if (! $playlist->xtream_config) {
            return $this->transformM3uUrl($originalUrl);
        }

        $sourceConfig = $playlist->xtream_config;

        // Extract source provider details
        $sourceBaseUrl = rtrim((string) ($sourceConfig['server'] ?? $sourceConfig['url'] ?? ''), '/');
        $sourceUsername = (string) ($sourceConfig['username'] ?? '');
        $sourcePassword = (string) ($sourceConfig['password'] ?? '');

        // This profile's credentials and URL
        $profileUrl = $this->url ? rtrim($this->url, '/') : $sourceBaseUrl;
        $profileUsername = $this->username;
        $profilePassword = $this->password;

        // If any required value is missing, do not transform
        if (
            $sourceBaseUrl === '' ||
            $sourceUsername === '' ||
            $sourcePassword === '' ||
            $profileUsername === '' ||
            $profilePassword === '' ||
            $profileUrl === ''
        ) {
            return $originalUrl;
        }

        // Pattern matches:
        // http://domain:port/(live|series|movie)/username/password/<stream>
        $pattern =
            '#^'.preg_quote($sourceBaseUrl, '#').
            '/(live|series|movie)/'.preg_quote($sourceUsername, '#').
            '/'.preg_quote($sourcePassword, '#').
            '/(.+)$#';

        if (preg_match($pattern, $originalUrl, $matches)) {
            $streamType = $matches[1];
            $streamIdAndExtension = $matches[2];

            $transformedUrl = "{$profileUrl}/{$streamType}/{$profileUsername}/{$profilePassword}/{$streamIdAndExtension}";

            Log::debug('Profile URL transformation matched', [
                'profile_id' => $this->id,
                'profile_name' => $this->name ?? 'N/A',
                'playlist_id' => $playlist->id,
                'stream_type' => $streamType,
                'source_base_url' => $sourceBaseUrl,
                'profile_base_url' => $profileUrl,
                'source_user' => substr($sourceUsername, 0, 3).'***',
                'profile_user' => substr($profileUsername, 0, 3).'***',
                'stream_id' => $streamIdAndExtension,
                'original_url' => preg_replace('#/[^/]+/[^/]+/(live|series|movie)/#', '/***/***/\1/', $originalUrl),
                'transformed_url' => preg_replace('#/[^/]+/[^/]+/(live|series|movie)/#', '/***/***/\1/', $transformedUrl),
            ]);

            return $transformedUrl;
        }

        Log::warning('Profile URL transformation did NOT match', [
            'profile_id' => $this->id,
            'profile_name' => $this->name ?? 'N/A',
            'source_base_url' => $sourceBaseUrl,
            'profile_base_url' => $profileUrl,
            'original_url' => preg_replace('#/[^/]+/[^/]+/(live|series|movie)/#', '/***/***/\1/', $originalUrl),
            'pattern' => $pattern,
        ]);

        return $originalUrl;
    }

    /**
     * Whether the provider behind this profile can be queried for account info
     * (connection limits, expiry). Only Xtream-backed playlists expose a player
     * API; M3U playlists rely on the user-entered max_streams instead.
     */
    public function supportsProviderInfo(): bool
    {
        return (bool) $this->playlist?->xtream_config;
    }

    /**
     * Rewrite an M3U playlist's stream URL to this profile's account.
     *
     * M3U playlists carry no provider config, so the credentials to replace are the
     * primary profile's (parsed from the playlist's M3U URL when profiles were
     * enabled) or, when those are unknown, whatever the stream URL itself carries.
     * The profile's URL, when set, replaces the provider's scheme/host/port. URLs
     * in none of the recognised shapes (see parseStreamCredentials()) are returned
     * untouched.
     */
    private function transformM3uUrl(string $originalUrl): string
    {
        if ($this->is_primary || $originalUrl === '' || blank($this->username) || blank($this->password)) {
            return $originalUrl;
        }

        // Known primary credentials are matched strictly, like the Xtream path: a URL
        // that does not carry them (another host, a CDN token) is left alone rather
        // than having whatever it contains swapped out.
        $primary = $this->playlist?->primaryProfile();
        $primaryCredentialsKnown = $primary && filled($primary->username) && filled($primary->password);

        $source = $primaryCredentialsKnown
            ? ['username' => (string) $primary->username, 'password' => (string) $primary->password]
            : static::parseStreamCredentials($originalUrl);

        // The profile's own URL only relocates streams served by the primary account's
        // provider, as the Xtream path anchors on the source base URL. A stream on
        // another host (a CDN or edge node) keeps its host and only has its credentials
        // swapped. When the primary's provider base is unknown there is nothing to
        // anchor on and the profile URL applies.
        $primaryBaseUrl = static::baseUrlOf((string) $primary?->url);
        $targetBaseUrl = $primaryBaseUrl === null || strcasecmp(static::baseUrlOf($originalUrl) ?? '', $primaryBaseUrl) === 0
            ? $this->url
            : null;

        $transformedUrl = $source
            ? static::swapStreamCredentials(
                $originalUrl,
                $source['username'],
                $source['password'],
                (string) $this->username,
                (string) $this->password,
                $targetBaseUrl,
            )
            : null;

        if ($transformedUrl === null) {
            Log::warning('M3U profile URL transformation did NOT match', [
                'profile_id' => $this->id,
                'profile_name' => $this->name ?? 'N/A',
                'playlist_id' => $this->playlist_id,
                'primary_credentials_known' => $primaryCredentialsKnown,
                'original_url' => static::redactStreamUrl($originalUrl),
            ]);

            return $originalUrl;
        }

        Log::debug('M3U profile URL transformation matched', [
            'profile_id' => $this->id,
            'profile_name' => $this->name ?? 'N/A',
            'playlist_id' => $this->playlist_id,
            'profile_base_url' => $this->url,
            'original_url' => static::redactStreamUrl($originalUrl),
            'transformed_url' => static::redactStreamUrl($transformedUrl),
        ]);

        return $transformedUrl;
    }

    /**
     * Parse the account credentials out of a provider's M3U URL.
     *
     * Recognised shapes:
     *   - http://host/get.php?username=USER&password=PASS[&type=m3u_plus...]
     *   - http://host/(playlist|live)/USER/PASS/<file>
     *   - http://host/USER/PASS/<playlist file>, where the file is one of the names
     *     providers use (m3u, m3u8, m3u_plus, playlist.m3u...). Without that
     *     restriction any three-segment path (/iptv/countries/us.m3u) would read as
     *     credentials, and a wrongly "known" primary account would disable the
     *     stream-URL fallback for every pooled stream.
     *
     * Local file paths and URLs without credentials return null. The returned
     * url is the provider's scheme/host/port only.
     *
     * @return array{url: string, username: string, password: string}|null
     */
    public static function parseM3uUrlCredentials(?string $url): ?array
    {
        $url = trim((string) $url);

        if (! preg_match('#^(https?://[^/?\#]+)([^?\#]*)(?:\?([^\#]*))?#i', $url, $matches)) {
            return null;
        }

        $baseUrl = $matches[1];
        $path = $matches[2] ?? '';
        parse_str($matches[3] ?? '', $query);

        if (filled($query['username'] ?? null) && filled($query['password'] ?? null)) {
            return [
                'url' => $baseUrl,
                'username' => (string) $query['username'],
                'password' => (string) $query['password'],
            ];
        }

        if (
            preg_match('#^/(?:playlist|live)/([^/]+)/([^/]+)/[^/]+$#', $path, $pathMatches)
            || preg_match('#^/(?!(?:playlist|live)/)([^/]+)/([^/]+)/(?:playlist\.)?m3u(?:8|_plus)?$#i', $path, $pathMatches)
        ) {
            return [
                'url' => $baseUrl,
                'username' => $pathMatches[1],
                'password' => $pathMatches[2],
            ];
        }

        return null;
    }

    /**
     * Parse provider credentials out of a stream URL.
     *
     * Used for M3U playlists whose primary credentials are unknown (nothing could be
     * parsed from the playlist URL). Recognised shapes:
     *   - http://host/(live|series|movie)/USER/PASS/<stream>
     *   - http://host/USER/PASS/<numeric id>[.ext] (requiring a numeric id keeps
     *     arbitrary three-segment URLs from being read as credentials, and a
     *     stream-type prefix is never read as the username)
     *   - http://host/path?username=USER&password=PASS[&...]
     *
     * @return array{url: string, username: string, password: string}|null
     */
    public static function parseStreamCredentials(string $url): ?array
    {
        if (preg_match('#^(https?://[^/?\#]+)/(?:live|series|movie)/([^/?\#]+)/([^/?\#]+)/[^?\#]+#', $url, $matches)) {
            return ['url' => $matches[1], 'username' => $matches[2], 'password' => $matches[3]];
        }

        if (preg_match('#^(https?://[^/?\#]+)/(?!(?:live|series|movie)/)([^/?\#]+)/([^/?\#]+)/\d+(?:\.\w+)?(?:[?\#].*)?$#', $url, $matches)) {
            return ['url' => $matches[1], 'username' => $matches[2], 'password' => $matches[3]];
        }

        if (preg_match('#^(https?://[^/?\#]+)[^?\#]*\?([^\#]*)#', $url, $matches)) {
            parse_str($matches[2], $query);

            if (filled($query['username'] ?? null) && filled($query['password'] ?? null)) {
                return [
                    'url' => $matches[1],
                    'username' => (string) $query['username'],
                    'password' => (string) $query['password'],
                ];
            }
        }

        return null;
    }

    /**
     * Swap the credentials, and optionally the provider base URL, in a stream URL.
     *
     * Handles the three URL shapes listed on parseStreamCredentials(). Returns null
     * when the URL does not carry the source credentials in any of them. Credentials
     * in a query string are matched raw or URL-encoded and written URL-encoded.
     */
    public static function swapStreamCredentials(
        string $url,
        string $sourceUsername,
        string $sourcePassword,
        string $targetUsername,
        string $targetPassword,
        ?string $targetBaseUrl = null,
    ): ?string {
        if ($sourceUsername === '' || $sourcePassword === '' || $targetUsername === '' || $targetPassword === '') {
            return null;
        }

        $targetBaseUrl = filled($targetBaseUrl) ? rtrim($targetBaseUrl, '/') : null;
        $username = preg_quote($sourceUsername, '#');
        $password = preg_quote($sourcePassword, '#');

        // Prefixed form: http://host/(live|series|movie)/USER/PASS/<stream>
        if (preg_match('#^(https?://[^/?\#]+)/(live|series|movie)/'.$username.'/'.$password.'/(.+)$#', $url, $matches)) {
            return ($targetBaseUrl ?? $matches[1])."/{$matches[2]}/{$targetUsername}/{$targetPassword}/{$matches[3]}";
        }

        // Prefix-less form: http://host/USER/PASS/<stream>
        if (preg_match('#^(https?://[^/?\#]+)/'.$username.'/'.$password.'/(.+)$#', $url, $matches)) {
            return ($targetBaseUrl ?? $matches[1])."/{$targetUsername}/{$targetPassword}/{$matches[2]}";
        }

        // Query form: http://host/path?username=USER&password=PASS[&...]
        if (preg_match('#^(https?://[^/?\#]+)([^?\#]*)\?([^\#]*)(\#.*)?$#', $url, $matches)) {
            $query = $matches[3];
            $usernamePattern = '#(^|&)username=(?:'.$username.'|'.preg_quote(rawurlencode($sourceUsername), '#').')(?=&|$)#';
            $passwordPattern = '#(^|&)password=(?:'.$password.'|'.preg_quote(rawurlencode($sourcePassword), '#').')(?=&|$)#';

            if (preg_match($usernamePattern, $query) && preg_match($passwordPattern, $query)) {
                $query = preg_replace($usernamePattern, '$1username='.rawurlencode($targetUsername), $query, 1);
                $query = preg_replace($passwordPattern, '$1password='.rawurlencode($targetPassword), $query, 1);

                return ($targetBaseUrl ?? $matches[1]).$matches[2].'?'.$query.($matches[4] ?? '');
            }
        }

        return null;
    }

    /**
     * Normalise the URL and credentials a user entered for an M3U-backed profile.
     *
     * The URL may be the account's full M3U URL (e.g. .../get.php?username=U&password=P):
     * its credentials fill any blank username/password and the URL is reduced to the
     * provider base. Otherwise the query string and a trailing file segment are dropped
     * so the stored URL is the scheme/host/port the stream URL rewrite expects.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeM3uProfileInput(array $data): array
    {
        $url = trim((string) ($data['url'] ?? ''));

        if ($url === '') {
            return $data;
        }

        if ($credentials = static::parseM3uUrlCredentials($url)) {
            $data['url'] = $credentials['url'];
            $data['username'] = filled($data['username'] ?? null) ? $data['username'] : $credentials['username'];
            $data['password'] = filled($data['password'] ?? null) ? $data['password'] : $credentials['password'];

            return $data;
        }

        $data['url'] = static::providerBaseUrl($url);

        return $data;
    }

    /**
     * Reduce a provider URL to its base: the query string and a trailing file
     * segment (get.php, list.m3u8, ...) are dropped, path prefixes are kept.
     */
    public static function providerBaseUrl(string $url): string
    {
        $url = preg_replace('/[?#].*$/', '', trim($url));
        $url = preg_replace('#^(https?://[^/]+)(/.*?)?/[^/]*\.[^/]*$#', '$1$2', $url);

        return rtrim($url, '/');
    }

    /**
     * The scheme, host and port of a URL (http://host:8080), or null when the
     * string is not an http(s) URL.
     */
    private static function baseUrlOf(string $url): ?string
    {
        return preg_match('#^https?://[^/?\#]+#i', trim($url), $matches) ? $matches[0] : null;
    }

    /**
     * Mask the credentials a stream URL carries, for logging.
     */
    private static function redactStreamUrl(string $url): string
    {
        $parsed = static::parseStreamCredentials($url);

        if (! $parsed) {
            return $url;
        }

        return str_replace(
            [$parsed['username'], $parsed['password'], rawurlencode($parsed['username']), rawurlencode($parsed['password'])],
            '***',
            $url,
        );
    }
}
