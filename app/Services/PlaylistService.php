<?php

namespace App\Services;

use App\Jobs\MergeChannels;
use App\Jobs\UnmergeChannels;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service to handle playlist-related operations.
 */
class PlaylistService
{
    /**
     * Get the base URL of the application, including port if set
     *
     * @return string
     */
    public static function getBaseUrl($path = '')
    {
        // Normalize path
        if (empty($path)) {
            $path = null;
        }

        // Check if override URL is set in config
        $proxyUrlOverride = config('proxy.url_override');

        // See if override settings apply
        if (! $proxyUrlOverride || empty($proxyUrlOverride)) {
            try {
                $settings = app(GeneralSettings::class);
                $proxyUrlOverride = $settings->url_override ?? null;
            } catch (\Exception $e) {
            }
        }
        if ($proxyUrlOverride) {
            return rtrim($proxyUrlOverride, '/').($path ? '/'.ltrim($path, '/') : '');
        }

        // Manually construct base URL to ensure port is included (if not using HTTPS)
        $url = rtrim(config('app.url'), '/');
        $port = config('app.port');
        if (! Str::contains($url, 'https') && $port) {
            $url .= ':'.$port;
        }

        return $url.($path ? '/'.ltrim($path, '/') : '');
    }

    /**
     * Get URLs for the given playlist
     *
     * @param  Playlist|MergedPlaylist|CustomPlaylist|PlaylistAlias  $playlist
     * @return array
     */
    public static function getUrls($playlist)
    {
        // Get the first enabled auth (URLs can only contain one set of credentials)
        $playlistAuth = null;
        if (method_exists($playlist, 'playlistAuths')) {
            $playlistAuth = $playlist->playlistAuths()->where('enabled', true)->first();
        } elseif ($playlist instanceof PlaylistAlias) {
            // If PlaylistAlias, check if direct authentication is set
            $playlistAuth = $playlist->username && $playlist->password
                ? (object) ['username' => $playlist->username, 'password' => $playlist->password]
                : null;
        }
        $auth = null;
        if ($playlistAuth) {
            $auth = '?username='.urlencode($playlistAuth->username).'&password='.urlencode($playlistAuth->password);
        }

        // Get the base URLs
        // Build a path-based auth suffix for HDHR when auth is present. We keep query auth for
        // other endpoints (M3U/EPG) to retain backwards compatibility.
        $hdhrAuthPath = '';
        if ($playlistAuth) {
            $hdhrAuthPath = '/'.rawurlencode($playlistAuth->username).'/'.rawurlencode($playlistAuth->password);
        }

        if ($playlist->short_urls_enabled) {
            $shortUrls = collect($playlist->short_urls)->keyBy('type');

            $m3uData = $shortUrls->get('m3u');
            $hdhrData = $shortUrls->get('hdhr');
            $epgData = $shortUrls->get('epg');
            $epgZipData = $shortUrls->get('epg_zip');

            $m3uUrl = $m3uData ? url('/s/'.$m3uData['key']) : null;
            // For HDHR short URLs we append the auth path (if present). The short URL forwarding
            // will preserve the extra path so the final redirect becomes /{uuid}/hdhr/{user}/{pass}
            $hdhrUrl = $hdhrData ? url('/s/'.$hdhrData['key'].$hdhrAuthPath) : null;
            $epgUrl = $epgData ? url('/s/'.$epgData['key']) : null;

            // Since zipped url was added later, it might not be present in the short urls
            // Default to the route if not found
            $epgZipUrl = $epgZipData
                ? url('/s/'.$epgZipData['key'])
                : route('epg.generate.compressed', ['uuid' => $playlist->uuid]);
        } else {
            $m3uUrl = route('playlist.generate', ['uuid' => $playlist->uuid]);
            $epgUrl = route('epg.generate', ['uuid' => $playlist->uuid]);
            $epgZipUrl = route('epg.generate.compressed', ['uuid' => $playlist->uuid]);
            if ($hdhrAuthPath) {
                $hdhrUrl = route('playlist.hdhr.overview.auth', [
                    'uuid' => $playlist->uuid,
                    'username' => $playlistAuth->username,
                    'password' => $playlistAuth->password,
                ]);
            } else {
                $hdhrUrl = route('playlist.hdhr.overview', ['uuid' => $playlist->uuid]);
            }
        }

        // If auth set, append auth query parameters to URLs that expect query auth (M3U, EPG)
        if ($auth) {
            if ($m3uUrl) {
                $m3uUrl .= $auth;
            }
            // Do NOT append query auth to HDHR because many HDHR clients ignore query strings.
        }

        // Return the results
        return [
            'm3u' => $m3uUrl,
            'hdhr' => $hdhrUrl,
            'epg' => $epgUrl,
            'epg_zip' => $epgZipUrl,
            'authEnabled' => $playlistAuth ? true : false,
        ];
    }

    /**
     * Get Xtream API info for the given playlist
     *
     * @param  Playlist|MergedPlaylist|CustomPlaylist  $playlist
     * @return array
     */
    public static function getXtreamInfo($playlist)
    {
        // For Xtream API, we use the playlist UUID as the password
        // and the user's name as the username. This is valid of all playlist types.
        $auth = [
            'username' => $playlist->user->name,
            'password' => $playlist->uuid,
        ];
        if ($playlist instanceof PlaylistAlias) {
            // For PlaylistAlias, override default auth if set
            if ($playlist->username && $playlist->password) {
                $auth = [
                    'username' => $playlist->username,
                    'password' => $playlist->password,
                ];
            }
        }

        // Return the results
        return [
            'url' => url(''), // Base URL of the application
            ...$auth,
        ];
    }

    /**
     * Get the media flow proxy server URL
     *
     * @return string
     */
    public function getMediaFlowProxyServerUrl()
    {
        $settings = $this->getMediaFlowSettings();
        $proxyUrl = rtrim($settings['mediaflow_proxy_url'], '/');
        if ($settings['mediaflow_proxy_port']) {
            $proxyUrl .= ':'.$settings['mediaflow_proxy_port'];
        }

        return $proxyUrl;
    }

    /**
     * Get the media flow proxy URLs for the given playlist
     *
     * @param  Playlist|MergedPlaylist|CustomPlaylist  $playlist
     * @return array
     */
    public function getMediaFlowProxyUrls($playlist)
    {
        // Get the first enabled auth (URLs can only contain one set of credentials)
        if (method_exists($playlist, 'playlistAuths')) {
            $playlistAuth = $playlist->playlistAuths()->where('enabled', true)->first();
        } elseif ($playlist instanceof PlaylistAlias) {
            // If PlaylistAlias, check if direct authentication is set
            $playlistAuth = $playlist->username && $playlist->password
                ? (object) ['username' => $playlist->username, 'password' => $playlist->password]
                : null;
        }
        $auth = '';
        if ($playlistAuth) {
            $auth = '?username='.$playlistAuth->username.'&password='.$playlistAuth->password;
        }

        $settings = $this->getMediaFlowSettings();
        $proxyUrl = rtrim($settings['mediaflow_proxy_url'], '/');
        if ($settings['mediaflow_proxy_port']) {
            $proxyUrl .= ':'.$settings['mediaflow_proxy_port'];
        }

        // Example structure: http://localhost:8888/proxy/hls/manifest.m3u8?d=YOUR_M3U_EDITOR_PLAYLIST_URL&api_password=YOUR_PROXY_API_PASSWORD
        $playlistRoute = route('playlist.generate', ['uuid' => $playlist->uuid]);
        $playlistRoute .= $auth;
        $m3uUrl = $proxyUrl.'/proxy/hls/manifest.m3u8?d='.urlencode($playlistRoute);

        // Check if we're adding user-agent headers
        if ($settings['mediaflow_proxy_playlist_user_agent']) {
            $m3uUrl .= '&h_user-agent='.urlencode($playlist->user_agent);
        } elseif ($settings['mediaflow_proxy_user_agent']) {
            $m3uUrl .= '&h_user-agent='.urlencode($settings['mediaflow_proxy_user_agent']);
        }
        $m3uUrl .= '&api_password='.$settings['mediaflow_proxy_password'];

        // Return the results
        return [
            'm3u' => $m3uUrl,
            'authEnabled' => $playlistAuth ? true : false,
        ];
    }

    /**
     * Resolve a playlist by its UUID
     *
     * @param  string  $uuid
     * @return Playlist|MergedPlaylist|CustomPlaylist|PlaylistAlias|null
     */
    public function resolvePlaylistByUuid($uuid)
    {
        // First try to find primary playlist
        $playlist = Playlist::where('uuid', $uuid)->first();
        if ($playlist) {
            return $playlist;
        }

        // Then try merged playlist
        $playlist = MergedPlaylist::where('uuid', $uuid)->first();
        if ($playlist) {
            return $playlist;
        }

        // Then try custom playlist
        $playlist = CustomPlaylist::where('uuid', $uuid)->first();
        if ($playlist) {
            return $playlist;
        }

        // Finally try playlist alias
        $alias = PlaylistAlias::where('uuid', $uuid)->where('enabled', true)->first();
        if ($alias) {
            return $alias; // Return the alias itself, not the underlying playlist
        }

        return null;
    }

    public static function getChannelBaseUrl(Playlist|PlaylistAlias $source, $channelId): string
    {
        $config = $source instanceof PlaylistAlias
            ? $source->getPrimaryXtreamConfig()
            : $source->xtream_config;

        if (! $config) {
            return '';
        }

        $baseUrl = rtrim($config['url'], '/');
        $username = $config['username'];
        $password = $config['password'];

        return "{$baseUrl}/live/{$username}/{$password}/{$channelId}";
    }

    public static function getSeriesBaseUrl(Playlist|PlaylistAlias $source, $seriesId): string
    {
        $config = $source instanceof PlaylistAlias
            ? $source->getPrimaryXtreamConfig()
            : $source->xtream_config;

        if (! $config) {
            return '';
        }

        $baseUrl = rtrim($config['url'], '/');
        $username = $config['username'];
        $password = $config['password'];

        return "{$baseUrl}/series/{$username}/{$password}/{$seriesId}";
    }

    public static function makeFilesystemSafe(string $name, $replaceWith = ' '): string
    {
        switch ($replaceWith) {
            case 'space':
                $replaceWith = ' ';
                break;
            case 'underscore':
                $replaceWith = '_';
                break;
            case 'dash':
                $replaceWith = '-';
                break;
            case 'remove':
                $replaceWith = '';
                break;
            case 'period':
                $replaceWith = '.';
                break;
            default:
                $replaceWith = ' ';
                break;
        }

        // Replace filesystem-unsafe characters but preserve Unicode characters
        $unsafe = ['/', '\\', ':', '*', '?', '"', '<', '>', '|', "\0"];
        $safe = str_replace($unsafe, $replaceWith, $name);

        // Remove multiple spaces and trim
        $safe = preg_replace('/\s+/', ' ', trim($safe));

        // Remove leading/trailing dots (Windows limitation)
        $safe = trim($safe, '. ');

        return $safe ?: 'Unnamed';
    }

    public static function getEpisodeExample(): object
    {
        // Minimal example data for an episode to use for the path preview
        return (object) [
            'episode_num' => 1,
            'title' => 'Izuku Midoriya: Origin',
            'container_extension' => 'mkv',
            'info' => (object) [
                'season' => 1,
                'tmdb_id' => '1176693',
                'movie_image' => 'http://m3ueditor.test/logo-proxy/aHR0cDovLzIzLjIyNy4xNDcuMTcyOjgwL2ltYWdlcy9mODQyYjlkYTA5YWFjODFlYWRlYzU0YzY0NWU1ZDE3OS5qcGc=',
            ],
            'category' => 'Anime',
            'series' => (object) [
                'name' => 'My Hero Academia (2016)',
                'release_date' => '2016-04-03',
                'tmdb_id' => '65930',
                'metadata' => [
                    'name' => 'My Hero Academia (2016)',
                    'tmdb_id' => '65930',
                ],
            ],
        ];
    }

    public static function getVodExample(): object
    {
        // Minimal example data for VOD to use for the path preview
        return (object) [
            'title' => 'John Wick: Chapter 4 (2023)',
            'year' => '2023',
            'group' => '4K',
            'info' => [
                'name' => 'John Wick: Chapter 4',
                'tmdb_id' => 603692,
            ],
        ];
    }

    /**
     * Authenticate a playlist request
     *
     * @param  string  $username
     * @param  string  $password
     * @return array|bool [Playlist|MergedPlaylist|CustomPlaylist|null, string $authMethod, string $username, string $password] or false on failure
     */
    public function authenticate($username, $password): array|bool
    {
        if (empty($username) || empty($password)) {
            return false;
        }

        $playlist = null;
        $authMethod = 'none';

        // Method 1: Try to authenticate using PlaylistAuth credentials
        $playlistAuth = PlaylistAuth::where('username', $username)
            ->where('password', $password)
            ->where('enabled', true)
            ->first();

        if ($playlistAuth && $playlistAuth->isExpired()) {
            $playlistAuth = null;
        }

        if ($playlistAuth) {
            $playlist = $playlistAuth->getAssignedModel();
            if ($playlist) {
                // Load necessary relationships for the playlist
                $playlist->load([
                    'user',
                ]);
                $authMethod = 'playlist_auth';
            }
        }

        // Method 1b: Direct authentication with PlaylistAlias credentials
        $alias = PlaylistAlias::where('enabled', true)
            ->where('username', $username)
            ->where('password', $password)
            ->with(['user', 'playlist', 'customPlaylist'])
            ->first();

        if ($alias) {
            // If alias found but expired, treat as not found
            if ($alias->isExpired()) {
                return false;
            }

            return [
                $alias,
                'alias_auth',
                $username,
                $password,
            ];
        }
        // Method 2: Fall back to original authentication:
        //      (username = playlist owner, password = playlist UUID)
        if (! $playlist) {
            // Try to find playlist by UUID (password parameter)
            try {
                $playlist = Playlist::with([
                    'user',
                ])->where('uuid', $password)->firstOrFail();

                // Verify username matches playlist owner's name
                if ($playlist->user->name === $username) {
                    $authMethod = 'owner_auth';
                } else {
                    $playlist = null;
                }
            } catch (ModelNotFoundException $e) {
                // Try MergedPlaylist
                try {
                    $playlist = MergedPlaylist::with([
                        'user',
                    ])->where('uuid', $password)->firstOrFail();

                    // Verify username matches playlist owner's name
                    if ($playlist->user->name === $username) {
                        $authMethod = 'owner_auth';
                    } else {
                        $playlist = null;
                    }
                } catch (ModelNotFoundException $e) {
                    // Try CustomPlaylist
                    try {
                        $playlist = CustomPlaylist::with([
                            'user',
                        ])->where('uuid', $password)->firstOrFail();

                        // Verify username matches playlist owner's name
                        if ($playlist->user->name === $username) {
                            $authMethod = 'owner_auth';
                        } else {
                            $playlist = null;
                        }
                    } catch (ModelNotFoundException $e) {
                        // Try PlaylistAlias
                        try {
                            $playlist = PlaylistAlias::with([
                                'user',
                                'playlist',
                                'customPlaylist',
                            ])->where('uuid', $password)
                                ->where('enabled', true)
                                ->firstOrFail();

                            // Verify username matches playlist alias owner's name
                            if ($playlist->user->name === $username) {
                                $authMethod = 'owner_auth';
                            } else {
                                $playlist = null;
                            }
                        } catch (ModelNotFoundException $e) {
                            // No playlist found
                        }
                    }
                }
            }
        }

        return [
            $playlist,
            $authMethod,
            $username,
            $password,
        ];
    }

    /**
     * Determine if the media flow proxy is enabled
     *
     * @return bool
     */
    public function mediaFlowProxyEnabled()
    {
        return $this->getMediaFlowSettings()['mediaflow_proxy_url'] !== null;
    }

    /**
     * Get the media flow settings
     */
    public function getMediaFlowSettings(): array
    {
        // Get user preferences
        $userPreferences = app(GeneralSettings::class);
        $settings = [
            'mediaflow_proxy_url' => null,
            'mediaflow_proxy_port' => null,
            'mediaflow_proxy_password' => null,
            'mediaflow_proxy_user_agent' => null,
            'mediaflow_proxy_playlist_user_agent' => null,
        ];
        try {
            $settings = [
                'mediaflow_proxy_url' => $userPreferences->mediaflow_proxy_url ?? $settings['mediaflow_proxy_url'],
                'mediaflow_proxy_port' => $userPreferences->mediaflow_proxy_port ?? $settings['mediaflow_proxy_port'],
                'mediaflow_proxy_password' => $userPreferences->mediaflow_proxy_password ?? $settings['mediaflow_proxy_password'],
                'mediaflow_proxy_user_agent' => $userPreferences->mediaflow_proxy_user_agent ?? $settings['mediaflow_proxy_user_agent'],
                'mediaflow_proxy_playlist_user_agent' => $userPreferences->mediaflow_proxy_playlist_user_agent ?? $settings['mediaflow_proxy_playlist_user_agent'],
            ];
        } catch (Exception $e) {
            // Ignore
        }

        return $settings;
    }

    /**
     * Resolve exp_date for Xtream user_info based on the auth method used.
     * Xtream expects exp_date as a UNIX timestamp (seconds). Use "0" for no expiration.
     *
     * @param  mixed  $authRecord  PlaylistAuth|PlaylistAlias|Playlist|CustomPlaylist|MergedPlaylist
     */
    public function resolveXtreamExpDate($authRecord, string $authMethod, ?string $username, ?string $password): int
    {
        // PlaylistAuth login: authRecord is the assigned playlist model, so resolve by creds
        if ($authMethod === 'playlist_auth' && $username && $password) {
            $playlistAuth = PlaylistAuth::where('username', $username)
                ->where('password', $password)
                ->where('enabled', true)
                ->first();

            // If found, return the custom expiration timestamp
            return $playlistAuth?->expires_at?->timestamp ?? 0;
        }

        // Alias login
        if ($authMethod === 'alias_auth' && $authRecord instanceof PlaylistAlias) {
            return $authRecord?->expires_at?->timestamp ?? 0;
        }

        // Legacy (owner_auth) optional override
        if ($authMethod === 'owner_auth' && $username && $password) {
            $legacyOverride = PlaylistAuth::where('username', $username)
                ->where('password', $password)
                ->where('enabled', true)
                ->first();

            return $legacyOverride?->expires_at?->timestamp ?? 0;
        }

        // Default fallback
        return 0;
    }

    /**
     * Generate a timeshift URL for a given stream.
     *
     * @param  Playlist|MergedPlaylist|CustomPlaylist|PlaylistAlias  $playlist
     * @return string
     */
    public static function generateTimeshiftUrl(Request $request, string $streamUrl, $playlist)
    {
        // TiviMate sends utc/lutc as UNIX epochs (UTC). We only convert TZ + format.
        $utcPresent = $request->filled('utc');

        // Xtream API sends timeshift_duration (minutes) and timeshift_date (YYYY-MM-DD:HH-MM-SS)
        $xtreamTimeshiftPresent = $request->filled('timeshift_duration') && $request->filled('timeshift_date');

        // Use the portal/provider timezone (DST-aware). Prefer per-playlist; last resort UTC.
        $providerTz = $playlist?->server_timezone ?? null;

        // If no provider timezone set, attempt to get it from the Xtream config
        if (! $providerTz) {
            $providerTz = $playlist?->xtream_status['server_info']['timezone'] ?? 'Etc/UTC';
        }

        /* ── Timeshift SETUP (TiviMate → portal format) ───────────────────── */
        if ($utcPresent && ! $xtreamTimeshiftPresent) {
            $utc = (int) $request->query('utc'); // programme start (UTC epoch)
            $lutc = (int) ($request->query('lutc') ?? time()); // “live” now (UTC epoch)

            // duration (minutes) from start → now; ceil avoids off-by-one near edges
            $offset = max(1, (int) ceil(max(0, $lutc - $utc) / 60));

            // "…://host/live/u/p/<id>.<ext>" >>> "…://host/streaming/timeshift.php?username=u&password=p&stream=id&start=stamp&duration=offset"
            $rewrite = static function (string $url, string $stamp, int $offset): string {
                if (preg_match('~^(https?://[^/]+)/live/([^/]+)/([^/]+)/([^/]+)\.[^/]+$~', $url, $m)) {
                    [$_, $base, $user, $pass, $id] = $m;

                    return sprintf(
                        '%s/streaming/timeshift.php?username=%s&password=%s&stream=%s&start=%s&duration=%d',
                        $base,
                        $user,
                        $pass,
                        $id,
                        $stamp,
                        $offset
                    );
                }

                return $url; // fallback if pattern does not match
            };
        } elseif ($xtreamTimeshiftPresent) {
            /* ── Timeshift SETUP (Xtream API → Xtream API format) ─────────────────── */

            // Handle Xtream API timeshift format
            $duration = (int) $request->get('timeshift_duration'); // Duration in minutes
            $date = $request->get('timeshift_date'); // Format: YYYY-MM-DD:HH-MM-SS

            // "…://host/live/u/p/<id>.<ext>" >>> "…://host/timeshift/u/p/duration/stamp/<id>.<ext>"
            $rewrite = static function (string $url, string $stamp, int $offset): string {
                if (preg_match('~^(https?://[^/]+)/live/([^/]+)/([^/]+)/([^/]+)\.([^/]+)$~', $url, $m)) {
                    [$_, $base, $user, $pass, $id, $ext] = $m;

                    return sprintf(
                        '%s/timeshift/%s/%s/%d/%s/%s.%s',
                        $base,
                        $user,
                        $pass,
                        $offset,
                        $stamp,
                        $id,
                        $ext
                    );
                }

                return $url; // fallback if pattern does not match
            };
        }
        /* ─────────────────────────────────────────────────────────────────── */

        // ── Apply timeshift rewriting AFTER we know the provider timezone ──
        if ($utcPresent && ! $xtreamTimeshiftPresent) {
            // Convert the absolute UTC epoch from TiviMate to provider-local time string expected by timeshift.php
            $stamp = Carbon::createFromTimestampUTC($utc)
                ->setTimezone($providerTz)
                ->format('Y-m-d:H-i');

            $streamUrl = $rewrite($streamUrl, $stamp, $offset);

            // Helpful debug for verification
            Log::debug(sprintf(
                '[TIMESHIFT-M3U] utc=%d lutc=%d tz=%s start=%s offset(min)=%d final_url=%s',
                $utc,
                $lutc,
                $providerTz,
                $stamp,
                $offset,
                $streamUrl
            ));
        } elseif ($xtreamTimeshiftPresent) {
            // Convert Xtream API date format to timeshift URL format
            // Input: YYYY-MM-DD:HH-MM-SS, Output: YYYY-MM-DD:HH-MM
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2}):(\d{2})-(\d{2})-(\d{2})$/', $date, $matches)) {
                $stamp = sprintf('%s-%s-%s:%s-%s', $matches[1], $matches[2], $matches[3], $matches[4], $matches[5]);
            } else {
                // If the format doesn't match expected pattern, try to clean it up
                $stamp = preg_replace('/[^\d\-:]/', '', $date);
                $stamp = preg_replace('/:(\d{2})$/', '', $stamp); // Remove seconds if present
            }

            // Need to convert from app timezone to provider timezone
            $stamp = Carbon::createFromFormat('Y-m-d:H-i', $stamp, config('app.timezone', 'UTC'))
                ->setTimezone($providerTz)
                ->format('Y-m-d:H-i');

            $streamUrl = $rewrite($streamUrl, $stamp, $duration);

            // Helpful debug for verification
            Log::debug(sprintf(
                '[TIMESHIFT-XTREAM] duration=%d date=%s converted_stamp=%s final_url=%s',
                $duration,
                $date,
                $stamp,
                $streamUrl
            ));
        }

        return $streamUrl;
    }

    /**
     * Get the schema for adding items to a custom playlist.
     */
    public static function getAddToPlaylistSchema(string $type = 'channel'): array
    {
        $isSeries = $type === 'series';
        $itemLabel = $isSeries ? 'series' : 'channel(s)';
        $groupLabel = $isSeries ? 'Category' : 'Group';
        $tagFunction = $isSeries ? 'categoryTags' : 'groupTags';

        return [
            Select::make('playlist')
                ->required()
                ->live()
                ->label('Custom Playlist')
                ->helperText("Select the custom playlist you would like to add the selected $itemLabel to.")
                ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                ->afterStateUpdated(function (Set $set) {
                    $set('category', null);
                    $set('mode', 'select');
                })
                ->searchable(),

            Radio::make('mode')
                ->label("$groupLabel Selection")
                ->default('select')
                ->options([
                    'select' => "Select Existing $groupLabel",
                    'create' => "Create New $groupLabel",
                    'original' => "Use Original Item $groupLabel",
                ])
                ->live()
                ->visible(fn (Get $get) => (bool) $get('playlist')),

            Select::make('category')
                ->label("Select $groupLabel")
                ->required(fn (Get $get) => $get('mode') === 'select')
                ->visible(fn (Get $get) => $get('playlist') && $get('mode') === 'select')
                ->options(function (Get $get) use ($tagFunction) {
                    $customList = CustomPlaylist::find($get('playlist'));

                    if (! $customList) {
                        return [];
                    }

                    return $customList->$tagFunction()->get()
                        ->mapWithKeys(fn ($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                        ->toArray();
                })
                ->searchable(),

            TextInput::make('new_category')
                ->label("New $groupLabel Name")
                ->required(fn (Get $get) => $get('mode') === 'create')
                ->visible(fn (Get $get) => $get('playlist') && $get('mode') === 'create'),
        ];
    }

    /**
     * Add items to a custom playlist and optionally tag them.
     *
     * @param  iterable|Relation|Builder  $items
     * @param  array|string|null  $data
     */
    public static function addItemsToPlaylist(CustomPlaylist $playlist, $items, $data, string $type = 'channel'): void
    {
        $isSeries = $type === 'series';
        $tagFunction = $isSeries ? 'categoryTags' : 'groupTags';
        $relation = $isSeries ? 'series' : 'channels';
        $tagType = $isSeries ? $playlist->uuid.'-category' : $playlist->uuid;

        // Get IDs for syncing
        $ids = [];
        if ($items instanceof Relation || $items instanceof Builder) {
            $ids = $items->pluck('id');
        } elseif ($items instanceof Collection) {
            $ids = $items->pluck('id');
        } else {
            foreach ($items as $item) {
                $ids[] = $item->id;
            }
        }

        $playlist->$relation()->syncWithoutDetaching($ids);

        // Parse data
        $mode = 'select';
        $tagName = null;

        if (is_array($data)) {
            $mode = $data['mode'] ?? 'select';
            if ($mode === 'select') {
                $tagName = $data['category'] ?? null;
            } elseif ($mode === 'create') {
                $tagName = $data['new_category'] ?? null;
            }
        } else {
            $tagName = $data;
        }

        $playlistTags = $playlist->$tagFunction()->get();
        // Get iterator for tagging
        $cursor = ($items instanceof Builder || $items instanceof Relation)
            ? $items->cursor()
            : $items;

        if ($mode === 'original') {
            foreach ($cursor as $item) {
                // Determine original name
                $originalName = null;
                if ($isSeries) {
                    $originalName = $item->category->name ?? null;
                } else {
                    $originalName = $item->group;
                }

                if ($originalName) {
                    $tag = \Spatie\Tags\Tag::findOrCreate($originalName, $tagType);
                    $playlist->attachTag($tag);

                    $item->detachTags($playlistTags);
                    $item->attachTag($tag);
                }
            }
        } elseif ($tagName) {
            $tag = \Spatie\Tags\Tag::findOrCreate($tagName, $tagType);
            $playlist->attachTag($tag);

            foreach ($cursor as $item) {
                $item->detachTags($playlistTags);
                $item->attachTag($tag);
            }
        }
    }

    /**
     * Get the form schema for the "Merge Same ID" action.
     */
    public static function getMergeFormSchema(): array
    {
        return [
            Fieldset::make('Merge source configuration')
                ->schema([
                    Select::make('playlist_id')
                        ->required()
                        ->columnSpanFull()
                        ->label('Preferred Playlist')
                        ->options(Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
                        ->live()
                        ->searchable()
                        ->helperText('Select a playlist to prioritize as the master during the merge process.'),
                    Repeater::make('failover_playlists')
                        ->label('')
                        ->helperText('Select one or more playlists use as failover source(s).')
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->orderColumn('sort')
                        ->simple(
                            Select::make('playlist_failover_id')
                                ->label('Failover Playlists')
                                ->options(Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                        )
                        ->distinct()
                        ->columns(1)
                        ->addActionLabel('Add failover playlist')
                        ->columnSpanFull()
                        ->minItems(1)
                        ->defaultItems(1),
                ])
                ->columnSpanFull(),
            Fieldset::make('Merge behavior')
                ->schema([
                    Toggle::make('by_resolution')
                        ->label('Order by Resolution')
                        ->live()
                        ->helperText('⚠️ IPTV WARNING: This will analyze each stream to determine resolution, which may cause rate limiting or blocking with IPTV providers. Only enable if your provider allows stream analysis.')
                        ->default(false),
                    Toggle::make('deactivate_failover_channels')
                        ->label('Deactivate Failover Channels')
                        ->helperText('When enabled, channels that become failovers will be automatically disabled.')
                        ->default(false),
                    Toggle::make('prefer_catchup_as_primary')
                        ->label('Prefer catch-up channels as primary')
                        ->helperText('When enabled, catch-up channels will be selected as the master when available.')
                        ->default(false),
                    Toggle::make('exclude_disabled_groups')
                        ->label('Exclude disabled groups from master selection')
                        ->helperText('Channels from disabled groups will never be selected as master.')
                        ->default(false),
                    Toggle::make('force_complete_remerge')
                        ->label('Force complete re-merge')
                        ->helperText('Re-evaluate ALL existing failover relationships, not just unmerged channels.')
                        ->default(false),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Fieldset::make('Advanced Priority Scoring (optional)')
                ->schema([
                    Select::make('prefer_codec')
                        ->label('Preferred Codec')
                        ->options([
                            'hevc' => 'HEVC / H.265 (smaller file size)',
                            'h264' => 'H.264 / AVC (better compatibility)',
                        ])
                        ->placeholder('No preference')
                        ->helperText('Prioritize channels with a specific video codec.'),
                    TagsInput::make('priority_keywords')
                        ->label('Priority Keywords')
                        ->placeholder('Add keyword...')
                        ->helperText('Channels with these keywords in their name will be prioritized (e.g., "RAW", "LOCAL", "HD").')
                        ->splitKeys(['Tab', 'Return']),
                    Repeater::make('group_priorities')
                        ->label('Group Priority Weights')
                        ->helperText('Assign priority weights to specific groups. Higher weight = more preferred as master. Leave empty for default behavior.')
                        ->columnSpanFull()
                        ->columns(2)
                        ->schema([
                            Select::make('group_id')
                                ->label('Group')
                                ->options(fn () => Group::query()
                                    ->with(['playlist'])
                                    ->where(['user_id' => auth()->id(), 'type' => 'live'])
                                    ->get(['name', 'id', 'playlist_id'])
                                    ->transform(fn ($group) => [
                                        'id' => $group->id,
                                        'name' => $group->name.' ('.$group->playlist->name.')',
                                    ])->pluck('name', 'id')
                                )
                                ->searchable()
                                ->required(),
                            TextInput::make('weight')
                                ->label('Weight')
                                ->numeric()
                                ->default(100)
                                ->minValue(1)
                                ->maxValue(1000)
                                ->helperText('1-1000, higher = more preferred')
                                ->required(),
                        ])
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->addActionLabel('Add group priority')
                        ->defaultItems(0)
                        ->dehydrateStateUsing(function ($state) {
                            if (is_array($state) && ! empty($state)) {
                                $formatted = [];
                                foreach ($state as $item) {
                                    if (is_array($item) && isset($item['weight'])) {
                                        $groupId = $item['group_id'] ?? null;
                                        if (! $groupId) {
                                            continue;
                                        }
                                        $formatted[] = [
                                            'group_id' => $groupId,
                                            'weight' => (int) $item['weight'],
                                        ];
                                    }
                                }

                                return $formatted;
                            }

                            return [];
                        }),
                    Repeater::make('priority_attributes')
                        ->label('Priority Order')
                        ->helperText('Drag to reorder priority attributes. First attribute has highest priority. Leave empty for default order.')
                        ->columnSpanFull()
                        ->simple(
                            Select::make('attribute')
                                ->options([
                                    'playlist_priority' => '📋 Playlist Priority (from failover list order)',
                                    'group_priority' => '📁 Group Priority (from weights above)',
                                    'catchup_support' => '⏪ Catch-up/Replay Support',
                                    'resolution' => '📺 Resolution (requires stream analysis)',
                                    'codec' => '🎬 Codec Preference (HEVC/H264)',
                                    'keyword_match' => '🏷️ Keyword Match',
                                ])
                                ->required()
                        )
                        ->reorderable()
                        ->reorderableWithDragAndDrop()
                        ->distinct()
                        ->addActionLabel('Add priority attribute')
                        ->defaultItems(0)
                        ->afterStateHydrated(function ($component, $state) {
                            if (is_array($state) && ! empty($state)) {
                                $formatted = [];
                                foreach ($state as $item) {
                                    if (is_string($item)) {
                                        $formatted[] = ['attribute' => $item];
                                    } elseif (is_array($item) && isset($item['attribute'])) {
                                        $formatted[] = $item;
                                    }
                                }
                                $component->state($formatted);
                            }
                        }),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ];
    }

    /**
     * Build the weighted config array from merge form data.
     */
    public static function buildMergeWeightedConfig(array $data): ?array
    {
        $groupPriorities = $data['group_priorities'] ?? [];
        $priorityAttributes = collect($data['priority_attributes'] ?? [])
            ->pluck('attribute')
            ->filter()
            ->values()
            ->toArray();

        if (! empty($data['priority_keywords']) || ! empty($data['prefer_codec']) || ($data['exclude_disabled_groups'] ?? false) || ! empty($groupPriorities) || ! empty($priorityAttributes)) {
            return [
                'priority_keywords' => $data['priority_keywords'] ?? [],
                'prefer_codec' => $data['prefer_codec'] ?? null,
                'exclude_disabled_groups' => $data['exclude_disabled_groups'] ?? false,
                'group_priorities' => $groupPriorities,
                'priority_attributes' => $priorityAttributes,
            ];
        }

        return null;
    }

    /**
     * Get the "Merge Same ID" action.
     *
     * @param  bool  $groupScoped  Whether this action operates on a single group (receives $record as Group)
     */
    public static function getMergeAction(bool $groupScoped = false): Action
    {
        $action = Action::make('merge')
            ->label('Merge Same ID')
            ->schema(self::getMergeFormSchema())
            ->requiresConfirmation()
            ->icon('heroicon-o-arrows-pointing-in')
            ->modalIcon('heroicon-o-arrows-pointing-in')
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel('Merge now');

        if ($groupScoped) {
            $action
                ->modalDescription('Merge all channels with the same ID in this group into a single channel with failover.')
                ->action(function (Group $record, array $data): void {
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new MergeChannels(
                            user: auth()->user(),
                            playlists: collect($data['failover_playlists']),
                            playlistId: $data['playlist_id'],
                            checkResolution: $data['by_resolution'] ?? false,
                            deactivateFailoverChannels: $data['deactivate_failover_channels'] ?? false,
                            forceCompleteRemerge: $data['force_complete_remerge'] ?? false,
                            preferCatchupAsPrimary: $data['prefer_catchup_as_primary'] ?? false,
                            groupId: $record->id,
                            weightedConfig: self::buildMergeWeightedConfig($data),
                        ));
                });
        } else {
            $action
                ->modalDescription('Merge all channels with the same ID into a single channel with failover.')
                ->action(function (array $data): void {
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new MergeChannels(
                            user: auth()->user(),
                            playlists: collect($data['failover_playlists']),
                            playlistId: $data['playlist_id'],
                            checkResolution: $data['by_resolution'] ?? false,
                            deactivateFailoverChannels: $data['deactivate_failover_channels'] ?? false,
                            forceCompleteRemerge: $data['force_complete_remerge'] ?? false,
                            preferCatchupAsPrimary: $data['prefer_catchup_as_primary'] ?? false,
                            weightedConfig: self::buildMergeWeightedConfig($data),
                        ));
                });
        }

        return $action;
    }

    /**
     * Get the "Unmerge Same ID" action.
     *
     * @param  bool  $groupScoped  Whether this action operates on a single group (receives $record as Group)
     */
    public static function getUnmergeAction(bool $groupScoped = false): Action
    {
        $action = Action::make('unmerge')
            ->label('Unmerge Same ID')
            ->requiresConfirmation()
            ->icon('heroicon-o-arrows-pointing-out')
            ->color('warning')
            ->modalIcon('heroicon-o-arrows-pointing-out')
            ->modalSubmitActionLabel('Unmerge now');

        if ($groupScoped) {
            $action
                ->schema([
                    Toggle::make('reactivate_channels')
                        ->label('Reactivate disabled channels')
                        ->helperText('Enable channels that were previously disabled during merge.')
                        ->default(false),
                ])
                ->modalDescription('Unmerge all channels with the same ID in this group, removing all failover relationships.')
                ->action(function (Group $record, array $data): void {
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new UnmergeChannels(
                            user: auth()->user(),
                            groupId: $record->id,
                            reactivateChannels: $data['reactivate_channels'] ?? false,
                        ));
                });
        } else {
            $action
                ->schema([
                    Select::make('playlist_id')
                        ->label('Unmerge Playlist')
                        ->options(Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
                        ->live()
                        ->searchable()
                        ->helperText('Playlist to unmerge channels from (or leave empty to unmerge all).'),
                    Toggle::make('reactivate_channels')
                        ->label('Reactivate disabled channels')
                        ->helperText('Enable channels that were previously disabled during merge.')
                        ->default(false),
                ])
                ->modalDescription('Unmerge all channels with the same ID, removing all failover relationships.')
                ->action(function (array $data): void {
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new UnmergeChannels(
                            user: auth()->user(),
                            playlistId: $data['playlist_id'] ?? null,
                            reactivateChannels: $data['reactivate_channels'] ?? false,
                        ));
                });
        }

        return $action;
    }

    /**
     * Get the BulkAction for adding items to a custom playlist.
     *
     * @param  \Closure|null  $resolveRecordsCallback  Returns the items to add from the records: fn($records) => $records->flatMap->channels
     */
    public static function getAddToPlaylistBulkAction(string $name = 'add', string $type = 'channel', ?\Closure $resolveRecordsCallback = null): BulkAction
    {
        return BulkAction::make($name)
            ->label('Add to Custom Playlist')
            ->schema(self::getAddToPlaylistSchema($type))
            ->action(function (Collection $records, array $data) use ($type, $resolveRecordsCallback): void {
                $playlist = CustomPlaylist::findOrFail($data['playlist']);

                $items = $records;
                if ($resolveRecordsCallback) {
                    $items = $resolveRecordsCallback($records);
                }

                self::addItemsToPlaylist($playlist, $items, $data, $type);
            })
            ->after(function () {
                Notification::make()
                    ->success()
                    ->title('Items added to custom playlist')
                    ->body('The selected items have been added to the chosen custom playlist.')
                    ->send();
            })
            ->deselectRecordsAfterCompletion()
            ->requiresConfirmation()
            ->icon('heroicon-o-play')
            ->modalIcon('heroicon-o-play')
            ->modalDescription('Add the selected item(s) to the chosen custom playlist.')
            ->modalSubmitActionLabel('Add now');
    }

    /**
     * Get the Action for adding items to a custom playlist.
     *
     * @param  \Closure|null  $resolveRecordsCallback  Returns the items to add from the record: fn($record) => $record->channels()
     */
    public static function getAddToPlaylistAction(string $name = 'add', string $type = 'channel', ?\Closure $resolveRecordsCallback = null): Action
    {
        return Action::make($name)
            ->label('Add to Custom Playlist')
            ->schema(self::getAddToPlaylistSchema($type))
            ->action(function ($record, array $data) use ($type, $resolveRecordsCallback): void {
                $playlist = CustomPlaylist::findOrFail($data['playlist']);

                $items = $record;
                if ($resolveRecordsCallback) {
                    $items = $resolveRecordsCallback($record);
                }

                self::addItemsToPlaylist($playlist, $items, $data, $type);
            })
            ->after(function () {
                Notification::make()
                    ->success()
                    ->title('Items added to custom playlist')
                    ->body('The selected items have been added to the chosen custom playlist.')
                    ->send();
            })
            ->requiresConfirmation()
            ->icon('heroicon-o-play')
            ->modalIcon('heroicon-o-play')
            ->modalDescription('Add the items to the chosen custom playlist.')
            ->modalSubmitActionLabel('Add now');
    }
}
