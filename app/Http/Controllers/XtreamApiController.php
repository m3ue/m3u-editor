<?php

namespace App\Http\Controllers;

use App\Enums\ChannelLogoType;
use App\Enums\DvrMatchMode;
use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Enums\DvrSeriesMode;
use App\Enums\PlaylistChannelId;
use App\Events\ViewerFavoriteEvent;
use App\Facades\PlaylistFacade;
use App\Facades\ProxyFacade;
use App\Jobs\RefreshMediaServerLibraryJob;
use App\Models\ArrIntegration;
use App\Models\Category;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\EmbyLibraryMapping;
use App\Models\Epg;
use App\Models\EpgProgramme;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\MergedPlaylist;
use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\Series;
use App\Models\StreamProfile;
use App\Models\ViewerFavorite;
use App\Models\ViewerWatchProgress;
use App\Providers\VersionServiceProvider;
use App\Services\AIOStreamsAuthorizationService;
use App\Services\ContentRequestService;
use App\Services\DvrCapabilityGate;
use App\Services\DvrRecorderService;
use App\Services\EmbyPublicationCatalogService;
use App\Services\EpgCacheService;
use App\Services\LogoCacheService;
use App\Services\M3uProxyService;
use App\Services\VodFileNameService;
use App\Settings\GeneralSettings;
use App\Support\SeriesKey;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Tags\Tag;
use Symfony\Component\HttpFoundation\JsonResponse;

class XtreamApiController extends Controller
{
    private const REQUEST_ACTIONS = [
        'request_search',
        'request_submit',
        'request_history',
        'request_status',
        'request_dismiss',
    ];

    private const DVR_ACTIONS = [
        'get_dvr_recordings',
        'get_dvr_recording',
        'get_dvr_storage',
        'schedule_dvr',
        'create_dvr_series_rule',
        'update_dvr_series_rule',
        'cancel_dvr_recording',
        'delete_dvr_recording',
        'list_dvr_series_rules',
        'delete_dvr_series_rule',
        'search_epg_shows',
    ];

    private const FAVORITE_COLUMNS = [
        'content_type', 'stream_id', 'aio_item_id', 'imdb_id', 'tmdb_id',
        'aio_integration_id', 'title', 'thumbnail_url', 'item_type', 'favorited_at',
    ];

    /**
     * Max number of `recent_episodes` returned per show in `search_epg_shows`.
     * The list is the picker for which upcoming airing to schedule a recording
     * on (m3u-tv#204), so it has to be large enough that the next actionable
     * airing isn't pushed off the end by a deep schedule, while still bounding
     * payload growth.
     */
    private const int MAX_RECENT_EPISODES = 40;

    /**
     * Xtream API request handler.
     *
     * This endpoint serves as the primary interface for Xtream API interactions.
     * It requires authentication via username and password provided as query parameters.
     * The `action` query parameter dictates the specific operation to perform and the structure of the response.
     *
     * The `username` and `password` parameters are mandatory for all actions.
     *
     * You will use your m3u editor login username (default is admin), and the password will be your playlist unique identifier for the playlist you would like to access via the Xtream API.
     *
     * ## Supported Actions:
     *
     * ### panel (default)
     * Returns user authentication info and server details. This is the default action if none is specified. Returns the same response as: `get_user_info`, `get_account_info` and `get_server_info`.
     *
     * ### get_live_streams
     * Returns a JSON array of live stream objects. Only enabled, non-VOD channels are included.
     * Supports optional category filtering via `category_id` parameter.
     * Each stream object contains: `num`, `name`, `stream_type`, `stream_id`, `stream_icon`, `epg_channel_id`,
     * `added`, `category_id`, `category_ids`, `tv_archive`, `tv_archive_duration`, `custom_sid`, `thumbnail`, `direct_source`.
     * The `direct_source` field contains the proxy URL when proxy is enabled, otherwise the Xtream-style stream URL.
     * The `thumbnail` field contains the same value as `stream_icon`.
     * `tv_archive_duration` is in days. It falls back to `dev.default_epg_catchup_days` (env `DEFAULT_EPG_CATCHUP_DAYS`,
     * default 7) when `tv_archive` is `1` but the actual retention window is unknown (catchup enabled with no known
     * duration), and is `0` when catchup is unavailable or disabled entirely.
     *
     * ### get_vod_streams
     * Returns a JSON array of VOD channel objects (movies marked as VOD). Only enabled VOD channels are included.
     * Supports optional category filtering via `category_id` parameter.
     * Each object contains: `num`, `name`, `title`, `year`, `stream_type` (always "movie"), `stream_id`, `stream_icon`,
     * `rating`, `rating_5based`, `added`, `category_id`, `category_ids`, `tmdb`, `tmdb_id`, `container_extension`, `custom_sid`, `direct_source`.
     * The `direct_source` field contains the proxy URL when proxy is enabled, otherwise the Xtream-style movie URL.
     *
     * ### get_series
     * Returns a JSON array of series objects. Only enabled series are included.
     * Supports optional category filtering via `category_id` parameter.
     * Each object contains: `num`, `name`, `series_id`, `cover`, `plot`, `cast`, `director`, `genre`, `releaseDate`,
     * `last_modified`, `rating`, `rating_5based`, `backdrop_path`, `youtube_trailer`, `episode_run_time`, `category_id`.
     *
     * ### get_live_categories
     * Returns a JSON array of live stream categories/groups. Only groups with enabled, non-VOD channels are included.
     * Each category contains: `category_id`, `category_name`, `parent_id`.
     *
     * ### get_vod_categories
     * Returns a JSON array of VOD categories/groups. Only groups with enabled VOD channels are included.
     * Each category contains: `category_id`, `category_name`, `parent_id`.
     *
     * ### get_series_categories
     * Returns a JSON array of series categories. Only categories with enabled series are included.
     * Each category contains: `category_id`, `category_name`, `parent_id`.
     *
     * ### get_series_info
     * Returns detailed information for a specific series, including its seasons and episodes.
     * Requires `series_id` parameter to specify which series to retrieve.
     * Returns series info, seasons, and episode details.
     *
     * ### get_vod_info
     * Returns detailed information for a specific VOD/movie stream.
     * Requires `vod_id` parameter to specify which VOD stream to retrieve.
     * Returns movie information and metadata in a structured format.
     * Uses channel's `info` and `movie_data` fields when available, or builds data from other channel fields.
     *
     * ### get_short_epg
     * Returns a limited number of EPG programmes for a specific live stream/channel.
     * Requires `stream_id` parameter to specify which channel to retrieve EPG data for.
     * Supports optional `limit` parameter (default=4) to control the number of programmes returned.
     * Returns programmes from current time onwards, including currently playing programme if any.
     * Includes `now_playing` flag to indicate if the channel is currently streaming.
     *
     * ### get_simple_data_table
     * Returns full EPG data for a specific live stream/channel for the current date.
     * Requires `stream_id` parameter to specify which channel to retrieve EPG data for.
     * Returns all programmes for today with programme details and timing information.
     * Includes `now_playing` flag to indicate if the channel is currently streaming.
     *
     * ### m3u_plus
     * Redirects to the `m3u` method to generate an M3U playlist in the M3U Plus format.
     * `output` parameter is ignored for this action and will instead use your Playlist configuration for M3U Plus output.
     *
     * ### get_user_info
     * ### get_account_info
     * ### get_server_info
     * Returns account and server information including user details and allowed output formats.
     * This provides the same user information as the panel.
     * Contains: `username`, `password`, `message`, `auth`, `status`, `exp_date`, `is_trial`,
     * `active_cons`, `created_at`, `max_connections`, `allowed_output_formats`.
     *
     * ### create_dvr_series_rule
     * Creates a Series-type DVR recording rule for a show title. Query parameters:
     *   - `title` (string, required): The show title; matched by the chosen match mode.
     *   - `channel_id` (int, optional): Pin the rule to a specific playlist channel. **Omit
     *     for "any channel"** — DvrSchedulerService::resolveSeriesEpgScope() then scopes to
     *     all EPG-mapped channels in the playlist. `source_channel_id` is intentionally NOT
     *     accepted from the API; setting it would narrow scope back to a single channel.
     *   - `match_mode` (string, optional, default `contains`): One of `contains`, `exact`,
     *     `starts_with`, `tmdb`.
     *   - `series_mode` (string, optional): One of `all`, `new_flag`, `unique_se`. Defaults
     *     to `dvr_setting.default_series_mode` server-side.
     *   - `keep_last` (int, optional): Defaults to `dvr_setting.default_series_keep_last`.
     *   - `priority` (int, optional): Defaults to 50 via the column migration default.
     *     Clamped 0-100 when supplied. **Omit to inherit the column default; do not send
     *     a hardcoded value.**
     *   - `start_early_seconds` (int, optional): Seconds to start early. **Omit to inherit
     *     `dvr_setting.default_start_early_seconds`** (resolved at runtime). Blank is not
     *     coerced to 0 — `0` is a meaningful value (no padding) distinct from "inherit".
     *   - `end_late_seconds` (int, optional): Seconds to end late. Same omit-to-inherit
     *     semantics as `start_early_seconds`.
     * Returns `{success: true, rule_id: <int>}`. Returns 409 `{error, rule_id, duplicate: true}`
     * when a Series rule for the same normalized title already exists under this DVR
     * setting (+ auth).
     *
     * ### update_dvr_series_rule
     * Updates an existing Series-type DVR recording rule in place — never delete-and-recreate,
     * because deleting a rule cascades to its recordings and destroys recording history (and
     * changes the rule id). Query parameters:
     *   - `rule_id` (int, required): The id of the rule to update.
     *   - `channel_id` (int, optional): Pin the rule to a specific playlist channel. **Send as
     *     an empty value to switch to "any channel"**; the request body/param must be present
     *     (even if blank) to distinguish "set to any channel" from "leave channel unchanged".
     *   - `match_mode` (string, optional): One of `contains`, `exact`, `starts_with`, `tmdb`.
     *   - `series_mode` (string, optional): One of `all`, `new_flag`, `unique_se`.
     *   - `keep_last` (int, optional).
     *   - `priority` (int, optional): Clamped 0-100 when supplied.
     *   - `start_early_seconds` (int, optional).
     *   - `end_late_seconds` (int, optional).
     * **Omit-to-inherit on update:** only fields present in the request are applied; fields
     * that are absent are left at their current values. Never send placeholder values — a
     * field you intend to keep must be omitted, not sent as its current value. `series_mode`
     * is stored in lockstep with the legacy `new_only` flag (see task 16).
     * Returns `{success: true, rule_id: <int>}`.
     *
     * ### search_epg_shows
     * Searches EPG programmes across all EPG-mapped channels in the playlist (plus those
     * that aired in the last 24 hours for discoverability) and groups results by
     * SeriesKey-normalized title. Query parameter: `q` (string, required, min 2 chars).
     * Returns at most 100 results, sorted by next-airing first then by episode count.
     * Each result object contains: `normalized_title`, `display_title`, `has_series_rule`
     * (bool — whether a Series rule already exists for this title), `series_rule_id` (int|null
     * — the existing rule's id when `has_series_rule` is true, for delete-without-round-trip),
     * `channel_count`, `channels` (array of `{channel_id, channel_name}`), `episode_count`,
     * `next_airing_at` (ISO 8601 or null), `airing_now` (array — programmes currently
     * in progress on EPG-mapped channels; empty when none, never null; programmes with
     * an unknown `end_time` are excluded since progress can't be confirmed), and
     * `recent_episodes` (up to MAX_RECENT_EPISODES airings, upcoming first soonest, then
     * most-recent-past). Entry shape for `airing_now[]` and `recent_episodes[]` is identical.
     *
     *
     * @param  string  $uuid  The UUID of the playlist (required path parameter)
     * @param  Request  $request  The HTTP request containing query parameters:
     *                            - username (string, required): User's Xtream API username
     *                            - password (string, required): User's Xtream API password
     *                            - action (string, optional): Defaults to 'panel'. Determines the API action
     *                            - category_id (string, optional): Filter results by category ID (required for get_series, optional for get_live_streams and get_vod_streams)
     *                            - series_id (int, optional): Series ID (required for get_series_info action)
     *                            - vod_id (int, optional): VOD/Movie ID (required for get_vod_info action)
     *                            - stream_id (int, optional): Channel/Stream ID (required for get_short_epg and get_simple_data_table actions)
     *                            - limit (int, optional): Number of EPG programmes to return for get_short_epg (default=4)
     *
     * @response 200 scenario="Panel action response" {
     *   "user_info": {
     *     "username": "test_user",
     *     "password": "test_pass",
     *     "message": "",
     *     "auth": 1,
     *     "status": "Active",
     *     "exp_date": "1767225600",
     *     "is_trial": "0",
     *     "active_cons": 1,
     *     "created_at": "1640995200",
     *     "max_connections": "2",
     *     "allowed_output_formats": ["m3u8", "ts"]
     *   },
     *   "server_info": {
     *     "url": "https://example.com",
     *     "port": "443",
     *     "https_port": "443",
     *     "server_protocol": "https",
     *     "timezone": "UTC",
     *     "server_software": "M3U Proxy Editor Xtream API",
     *     "timestamp_now": "1719187200",
     *     "time_now": "2025-06-20 12:00:00"
     *   }
     * }
     * @response 200 scenario="Live streams response" [
     *   {
     *     "num": 1,
     *     "name": "CNN HD",
     *     "stream_type": "live",
     *     "stream_id": "12345",
     *     "stream_icon": "https://example.com/logos/cnn.png",
     *     "epg_channel_id": "cnn.us",
     *     "added": "1640995200",
     *     "category_id": "1",
     *     "category_ids": [1],
     *     "tv_archive": 1,
     *     "tv_archive_duration": 7,
     *     "custom_sid": "cnn-hd",
     *     "thumbnail": "https://example.com/logos/cnn.png",
     *     "direct_source": ""
     *   }
     * ]
     * @response 200 scenario="VOD streams response" [
     *   {
     *     "num": 1,
     *     "name": "The Matrix",
     *     "title": "The Matrix",
     *     "year": "1999",
     *     "stream_type": "movie",
     *     "stream_id": "67890",
     *     "stream_icon": "https://example.com/covers/matrix.jpg",
     *     "rating": "8.7",
     *     "rating_5based": 4.35,
     *     "added": "1640995200",
     *     "category_id": "3",
     *     "category_ids": [3],
     *     "tmdb": "603",
     *     "tmdb_id": 603,
     *     "container_extension": "mkv",
     *     "custom_sid": "the-matrix",
     *     "direct_source": ""
     *   }
     * ]
     * @response 200 scenario="Series response" [
     *   {
     *     "num": 1,
     *     "name": "Breaking Bad",
     *     "series_id": 101,
     *     "cover": "https://example.com/covers/breaking_bad.jpg",
     *     "plot": "A high school chemistry teacher turned meth cook...",
     *     "cast": "Bryan Cranston, Aaron Paul",
     *     "director": "Vince Gilligan",
     *     "genre": "Crime, Drama",
     *     "releaseDate": "2008-01-20",
     *     "last_modified": "1640995200",
     *     "rating": "9.5",
     *     "rating_5based": 4.75,
     *     "backdrop_path": [],
     *     "youtube_trailer": "HhesaQXLuRY",
     *     "episode_run_time": "47",
     *     "category_id": "2"
     *   }
     * ]
     * @response 200 scenario="Series info response" {
     *   "info": {
     *     "name": "Breaking Bad",
     *     "cover": "https://example.com/covers/breaking_bad.jpg",
     *     "plot": "A high school chemistry teacher turned meth cook...",
     *     "cast": "Bryan Cranston, Aaron Paul",
     *     "director": "Vince Gilligan",
     *     "genre": "Crime, Drama",
     *     "releaseDate": "2008-01-20",
     *     "last_modified": "1640995200",
     *     "rating": "9.5",
     *     "rating_5based": 4.75,
     *     "backdrop_path": [],
     *     "youtube_trailer": "HhesaQXLuRY",
     *     "episode_run_time": "47",
     *     "category_id": "2"
     *   },
     *   "episodes": {
     *     "1": [
     *       {
     *         "id": "1001",
     *         "episode_num": 1,
     *         "title": "Pilot",
     *         "container_extension": "mp4",
     *         "info": {
     *             "release_date" => "2024-06-29"
     *             "plot" => "Kafka's final fate is determined as the monster within him tries to take control."
     *             "duration_secs" => 1440
     *             "duration" => "00:24:00"
     *             "movie_image" => "http://23.227.147.172:80/images/e11236b82442615bc6e44d3555dce478.jpg"
     *             "bitrate" => 0
     *             "rating" => "7.3"
     *             "season" => "1"
     *             "tmdb_id" => "5188924"
     *             "cover_big" => "http://23.227.147.172:80/images/e11236b82442615bc6e44d3555dce478.jpg"
     *         },
     *         "added": "1640995200",
     *         "season": 1,
     *         "stream_id": "1001",
     *         "direct_source": ""
     *       }
     *     ]
     *   },
     *   "seasons": {
     *     "1": []
     *   }
     * }
     * @response 200 scenario="Live categories response" [
     *   {
     *     "category_id": "1",
     *     "category_name": "News",
     *     "parent_id": 0
     *   },
     *   {
     *     "category_id": "2",
     *     "category_name": "Sports",
     *     "parent_id": 0
     *   }
     * ]
     * @response 200 scenario="VOD categories response" [
     *   {
     *     "category_id": "1",
     *     "category_name": "Action Movies",
     *     "parent_id": 0
     *   },
     *   {
     *     "category_id": "2",
     *     "category_name": "Comedy Movies",
     *     "parent_id": 0
     *   }
     * ]
     * @response 200 scenario="Series categories response" [
     *   {
     *     "category_id": "1",
     *     "category_name": "Drama Series",
     *     "parent_id": 0
     *   },
     *   {
     *     "category_id": "2",
     *     "category_name": "Comedy Series",
     *     "parent_id": 0
     *   }
     * ]
     * @response 200 scenario="Short EPG response" {
     *   "epg_listings": [
     *     {
     *       "id": "8037716",
     *       "epg_id": "8",
     *       "title": "Morning News",
     *       "lang": "en",
     *       "start": "2025-08-14 07:00:00",
     *       "end": "2025-08-14 07:15:00",
     *       "description": "Latest morning news and updates",
     *       "channel_id": "cnn.us",
     *       "start_timestamp": "1755154800",
     *       "stop_timestamp": "1755155700",
     *       "now_playing": 1,
     *       "has_archive": 0
     *     },
     *     {
     *       "id": "8037717",
     *       "epg_id": "8",
     *       "title": "Business Report",
     *       "lang": "en",
     *       "start": "2025-08-14 07:15:00",
     *       "end": "2025-08-14 07:30:00",
     *       "description": "Financial market updates",
     *       "channel_id": "cnn.us",
     *       "start_timestamp": "1755155700",
     *       "stop_timestamp": "1755156600",
     *       "now_playing": 0,
     *       "has_archive": 0
     *     }
     *   ]
     * }
     * @response 200 scenario="Simple date table response" {
     *   "epg_listings": [
     *     {
     *       "id": "8037716",
     *       "epg_id": "8",
     *       "title": "Morning News",
     *       "lang": "en",
     *       "start": "2025-08-14 07:00:00",
     *       "end": "2025-08-14 07:15:00",
     *       "description": "Latest morning news and updates",
     *       "channel_id": "cnn.us",
     *       "start_timestamp": "1755154800",
     *       "stop_timestamp": "1755155700",
     *       "now_playing": 1,
     *       "has_archive": 0
     *     }
     *   ]
     * }
     * @response 200 scenario="Account info response" {
     *   "username": "test_user",
     *   "password": "test_pass",
     *   "message": "",
     *   "auth": 1,
     *   "status": "Active",
     *   "exp_date": "1767225600",
     *   "is_trial": "0",
     *   "active_cons": 1,
     *   "created_at": "1640995200",
     *   "max_connections": "2",
     *   "allowed_output_formats": ["m3u8", "ts"]
     * }
     * @response 400 scenario="Bad Request" {"error": "Invalid action"}
     * @response 400 scenario="Missing category_id for get_series" {"error": "category_id parameter is required for get_series action"}
     * @response 400 scenario="Missing series_id for get_series_info" {"error": "series_id parameter is required for get_series_info action"}
     * @response 400 scenario="Missing stream_id for get_short_epg" {"error": "stream_id parameter is required for get_short_epg action"}
     * @response 400 scenario="Missing stream_id for get_simple_data_table" {"error": "stream_id parameter is required for get_simple_data_table action"}
     * @response 401 scenario="Unauthorized - Missing Credentials" {"error": "Unauthorized - Missing credentials"}
     * @response 401 scenario="Unauthorized - Invalid Credentials" {"error": "Unauthorized"}
     * @response 404 scenario="Not Found (e.g., playlist not found)" {"error": "Playlist not found"}
     * @response 404 scenario="Series not found" {"error": "Series not found or not enabled"}
     *
     * @unauthenticated
     */
    public function handle(Request $request)
    {
        $action = (string) $request->input('action', 'panel');
        if (in_array($action, self::REQUEST_ACTIONS, true)) {
            $credentialValidator = Validator::make($request->all(), [
                'username' => ['required', 'string'],
                'password' => ['required', 'string'],
            ]);
            if ($credentialValidator->fails()) {
                return $this->requestValidationError($credentialValidator->errors()->keys());
            }
        }

        // Authenticate the user based on the provided credentials
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);
        [$playlist, $authMethod, $username, $password] = $this->authenticate($request);

        // If no authentication method worked, return error
        if (! $playlist || $authMethod === 'none') {
            if (in_array($action, self::REQUEST_ACTIONS, true)) {
                return $this->requestError('authentication_failed', 'The credentials are invalid.', 401);
            }

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $playlistAuth = $authMethod === 'playlist_auth'
            ? PlaylistAuth::where('username', $username)
                ->where('password', $password)
                ->where('enabled', true)
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->first()
            : null;

        $urlSafePass = urlencode($password);
        $urlSafeUser = urlencode($username);

        // Check if Custom Playlist (or Custom Playlist via Alias) as we handle these differently
        $isCustomPlaylist = $playlist instanceof CustomPlaylist || ($playlist instanceof PlaylistAlias && $playlist->custom_playlist_id);
        $tagUuid = $playlist->uuid; // Default to Playlist UUID
        if ($isCustomPlaylist && $playlist instanceof PlaylistAlias) {
            $playlist->load('customPlaylist');
            $tagUuid = $playlist->customPlaylist->uuid; // PlaylistAlias case, get the attached CustomPlaylist UUID
        }

        // Check if this is a network playlist (pseudo-TV channels from media server content)
        $isNetworkPlaylist = $playlist instanceof Playlist && $playlist->is_network_playlist;

        // Resolve the disable_catchup flag from the source Playlist
        $sourcePlaylist = $playlist instanceof Playlist
            ? $playlist
            : ($playlist instanceof PlaylistAlias ? $playlist->playlist : null);
        $disableCatchup = (bool) ($sourcePlaylist->disable_catchup ?? false);

        // Resolve alias group filter — only needed for the categories list endpoints.
        // Channel/series stream queries are filtered automatically via the PlaylistAlias
        // channels() / series() relationships, so no per-query wiring is required there.
        // For standard playlists these are provider group names matched against
        // group_internal; for custom playlists they are the displayed category names.
        $aliasLiveGroupFilter = $playlist instanceof PlaylistAlias
            ? $playlist->getAllowedLiveGroupNames()
            : [];
        $aliasVodGroupFilter = $playlist instanceof PlaylistAlias
            ? $playlist->getAllowedVodGroupNames()
            : [];
        $aliasCategoryFilter = $playlist instanceof PlaylistAlias
            ? $playlist->getAllowedCategoryNames()
            : [];

        $baseUrl = ProxyFacade::getBaseUrl();
        $rateLimitedResponse = $this->requestRateLimit($action, $playlist, $authMethod, $playlistAuth);
        if ($rateLimitedResponse) {
            return $rateLimitedResponse;
        }

        if (
            $action === 'panel' ||
            $action === 'get_user_info' ||
            $action === 'get_account_info' ||
            $action === 'get_server_info' ||
            empty($request->input('action'))
        ) {
            $now = Carbon::now();
            $xtreamStatus = $playlist->xtream_status ?? null;
            if ($xtreamStatus) {
                $expires = $xtreamStatus['user_info']['exp_date']
                    ? $xtreamStatus['user_info']['exp_date']
                    : $now->copy()->startOfYear()->addYears(1)->timestamp;
                $streams = (int) $playlist->streams === 0
                    ? ($xtreamStatus['user_info']['max_connections'] ?? $playlist->streams ?? 1)
                    : $playlist->streams;
                $activeConnections = (int) ($xtreamStatus['user_info']['active_cons'] ?? 0);
            } else {
                $expires = $now->copy()->startOfYear()->addYears(1)->timestamp;
                $streams = $playlist->streams ?? 1;
                $activeConnections = 0;
            }
            // Override max_connections when the request is authenticated via a PlaylistAuth
            // that has a specific per-auth limit configured.
            if ($playlistAuth?->max_connections) {
                $streams = $playlistAuth->max_connections;
            }

            $outputFormats = ['m3u8', 'ts'];
            if ($playlist->enable_proxy) {
                // For PlaylistAlias, xtream_config is a list of configs — use effective playlist's config for output format
                $xtreamConfig = $playlist instanceof PlaylistAlias
                    ? ($playlist->getEffectivePlaylist()?->xtream_config ?? null)
                    : ($playlist->xtream_config ?? null);
                if ($xtreamConfig) {
                    $proxyOutput = $xtreamConfig['output'] ?? 'ts';
                    $outputFormats = $proxyOutput === 'hls' ? ['m3u8'] : [$proxyOutput];
                }
                $activeConnections = M3uProxyService::getPlaylistActiveStreamsCount($playlist);
            }

            $expDate = PlaylistFacade::resolveXtreamExpDate(
                $playlist,
                $authMethod,
                $username,
                $password
            );

            if (empty($expDate) || (int) $expDate === 0) {
                $expDate = $expires;
            }

            $settings = app(GeneralSettings::class);
            $message = $settings->xtream_api_message ?? '';
            $enhancedOutputEnabled = $settings->app_output_enabled ?? false;

            $userInfo = [
                'username' => $username,
                'password' => $password,
                'message' => (string) $message,
                'auth' => 1, // Authenticated successfully
                'status' => 'Active', // No inactive playlists should reach this point
                'exp_date' => (string) $expDate,
                'is_trial' => '0', // Trial accounts not supported
                'active_cons' => (string) $activeConnections,
                'created_at' => (string) ($playlist->user ? $playlist->user->created_at->timestamp : $now->timestamp),
                'max_connections' => (string) $streams,
                'allowed_output_formats' => $outputFormats,
            ];

            // Parse base URL to extract components
            $parsedUrl = parse_url($baseUrl);
            $scheme = $parsedUrl['scheme'] ?? 'http';
            $host = $parsedUrl['host'];
            $port = isset($parsedUrl['port']) ? (string) $parsedUrl['port'] : '80';

            $port = $settings->xtream_api_details['http_port'] ?? $port;
            $httpsPort = $settings->xtream_api_details['https_port'] ?? '443';

            $serverInfo = [
                'url' => $host,
                'port' => (string) $port, // Should be 80 for HTTP, otherwise use the specified port (e.g.: 36400
                'https_port' => (string) $httpsPort, // Should always be 443 for HTTPS
                'server_protocol' => $scheme,
                'rtmp_port' => '8001', // RTMP not available currently, we'll just return the default RTMP port
                // Timestamps will use the passed in timezone (server timezone)
                'timestamp_now' => $now->timestamp,
                'time_now' => $now->toDateTimeString(),
                // We'll set the timezone to the server timezone
                'timezone' => Config::get('app.timezone', 'UTC'),
                'process' => true, // Always true
            ];

            $payload = [
                'user_info' => $userInfo,
                'server_info' => $serverInfo,
            ];

            // If enhanced output is enabled, include the m3u_editor payload with version and features
            // This is required for the M3U TV app to connect via the Xtream API and resolve the features available for the playlist.
            if ($enhancedOutputEnabled) {
                $features = $this->resolveM3uEditorFeatures($playlist, $authMethod, $playlistAuth);
                $aiostreamsData = $this->resolveAIOStreamsData($playlist, $features);

                $m3uEditorPayload = [
                    'version' => VersionServiceProvider::getVersion(),
                    'features' => $features,
                ];

                if (in_array('requests', $features, true)) {
                    $requestPlaylist = $this->authorizedRequestPlaylist($playlist, $authMethod, $playlistAuth);

                    $m3uEditorPayload['requests'] = [
                        'api_version' => 1,
                        'actions' => [
                            'search' => 'request_search',
                            'submit' => 'request_submit',
                            'history' => 'request_history',
                            'status' => 'request_status',
                            'dismiss' => 'request_dismiss',
                        ],
                        'content_types' => $requestPlaylist
                            ? app(ContentRequestService::class)->contentTypes($requestPlaylist)
                            : [],
                        'approval_behavior' => $playlistAuth->auto_approve_requests
                            ? 'auto_approval'
                            : 'pending_approval',
                        'error_codes' => [
                            'invalid_request',
                            'authentication_failed',
                            'request_access_denied',
                            'rate_limited',
                            'providers_unavailable',
                            'provider_unavailable',
                            'submission_failed',
                            'invalid_integration',
                            'invalid_seasons',
                            'not_found',
                            'already_requested',
                            'already_available',
                            'request_not_found',
                            'request_not_dismissible',
                        ],
                    ];
                }

                if (! empty($aiostreamsData)) {
                    $m3uEditorPayload['aiostreams'] = $aiostreamsData;
                }

                $proxyData = $this->resolveProxyData($playlist, $features, $authMethod, $playlistAuth);
                if (! empty($proxyData)) {
                    $m3uEditorPayload['proxy'] = $proxyData;
                }

                if ($this->canAdvertiseLibraryPublishing($playlist, $authMethod, $playlistAuth)) {
                    $m3uEditorPayload['library_publishing'] = [
                        'api_version' => 1,
                        'actions' => [
                            'register_publisher' => 'm3u_editor_register_publisher',
                            'catalog' => 'm3u_editor_catalog',
                            'sync_result' => 'm3u_editor_sync_result',
                        ],
                        'snapshot_mode' => 'full',
                        'features' => [
                            'library_mappings',
                            'variants',
                            'provider_failover',
                            'local_nfo',
                            'revision_metadata',
                        ],
                    ];
                }

                $payload['m3u_editor'] = $m3uEditorPayload;
            }

            return response()->json($payload);
        } elseif ($action === 'get_live_streams') {
            // Handle network playlists - return networks as live streams
            if ($isNetworkPlaylist) {
                return $this->getNetworkLiveStreams($playlist, $baseUrl);
            }

            $categoryId = $request->input('category_id');

            // Use the optimised query: JOINs instead of eager loads, SQL-level ordering, cursor-compatible.
            $channelsQuery = PlaylistGenerateController::getChannelQuery($playlist, isVod: false);

            // For custom playlists, pull the tag ID and pivot channel number via correlated subqueries
            // so category_id and channel numbering are resolved without N+1 tag queries or relying
            // on the BelongsToMany pivot hydration (which cursor() does not trigger).
            if ($isCustomPlaylist) {
                $customPlaylistId = ($playlist instanceof PlaylistAlias) ? $playlist->custom_playlist_id : $playlist->id;
                $channelsQuery
                    ->selectRaw(
                        '(SELECT t.id FROM taggables tb INNER JOIN tags t ON t.id = tb.tag_id WHERE tb.taggable_id = channels.id AND tb.taggable_type = ? AND t.type = ? ORDER BY t.order_column ASC LIMIT 1) as custom_group_id',
                        [Channel::class, $tagUuid]
                    )
                    ->selectRaw(
                        '(SELECT ccp.channel_number FROM channel_custom_playlist ccp WHERE ccp.channel_id = channels.id AND ccp.custom_playlist_id = ?) as ccp_channel_number',
                        [$customPlaylistId]
                    );
            }

            // Apply category filtering when requested.
            if ($categoryId && $categoryId !== 'all') {
                if ($isCustomPlaylist) {
                    $channelsQuery->where(function ($query) use ($categoryId, $tagUuid) {
                        $query->whereHas('tags', function ($tagQuery) use ($categoryId, $tagUuid) {
                            $tagQuery->where('type', $tagUuid)
                                ->where('id', $categoryId);
                        })->orWhere(function ($subQuery) use ($categoryId, $tagUuid) {
                            $subQuery->whereDoesntHave('tags', function ($tagQuery) use ($tagUuid) {
                                $tagQuery->where('type', $tagUuid);
                            })->where('group_id', $categoryId);
                        });
                    });
                } else {
                    $channelsQuery->where('group_id', $categoryId);
                }
            }

            $cursor = $channelsQuery->cursor();

            return response()->stream(function () use ($cursor, $playlist, $baseUrl, $isCustomPlaylist, $disableCatchup) {
                $idChannelBy = $playlist->id_channel_by;
                $channelNumber = $playlist->auto_channel_increment ? $playlist->channel_start - 1 : 0;

                echo '[';
                $first = true;
                foreach ($cursor as $channel) {
                    if (! $first) {
                        echo ',';
                    }

                    $streamIcon = $baseUrl.'/placeholder.png';
                    if ($channel->logo) {
                        $streamIcon = $channel->logo;
                    } elseif ($channel->logo_type === ChannelLogoType::Epg && $channel->epg_icon) {
                        $streamIcon = $channel->epg_icon;
                    } elseif ($channel->logo_type === ChannelLogoType::Channel && ($channel->logo || $channel->logo_internal)) {
                        $logo = $channel->logo ?? $channel->logo_internal ?? '';
                        $streamIcon = filter_var($logo, FILTER_VALIDATE_URL) ? $logo : $baseUrl."/$logo";
                    }
                    if ($playlist->enable_logo_proxy && filter_var($streamIcon, FILTER_VALIDATE_URL) && ! str_starts_with($streamIcon, url('/'))) {
                        $streamIcon = LogoProxyController::generateProxyUrl($streamIcon);
                    }

                    $channelCategoryId = 'all';
                    if ($isCustomPlaylist) {
                        if (! empty($channel->custom_group_id)) {
                            $channelCategoryId = (string) $channel->custom_group_id;
                        } elseif ($channel->group_id) {
                            $channelCategoryId = (string) $channel->group_id;
                        }
                    } elseif ($channel->group_id) {
                        $channelCategoryId = (string) $channel->group_id;
                    }

                    $channelNo = ($isCustomPlaylist && ! empty($channel->ccp_channel_number))
                        ? (int) $channel->ccp_channel_number
                        : $channel->channel;
                    if (! $channelNo && ($playlist->auto_channel_increment || $idChannelBy === PlaylistChannelId::Number)) {
                        $channelNo = ++$channelNumber;
                    }

                    $tvgId = $channel->resolveTvgId($idChannelBy, $channelNo);

                    if (empty($tvgId)) {
                        $tvgId = $channel->source_id ?? $channel->id;
                    }

                    // Make sure TVG ID only contains characters and numbers
                    $tvgId = preg_replace(config('dev.tvgid.regex'), '', $tvgId);

                    $liveStream = [
                        'num' => $channelNo,
                        'name' => $channel->title_custom ?? $channel->title,
                        'stream_type' => 'live',
                        'stream_id' => $channel->id,
                        'stream_icon' => $streamIcon,
                        'epg_channel_id' => $tvgId,
                        'added' => (string) $channel->created_at->timestamp,
                        'category_id' => $channelCategoryId,
                        'category_ids' => [(int) $channelCategoryId],
                        'tv_archive' => (! $disableCatchup && ($channel->catchup || $channel->shift)) ? 1 : 0,
                        'tv_archive_duration' => $this->resolveTvArchiveDuration($channel, $disableCatchup),
                        'custom_sid' => $channel->stream_id_custom ?? '',
                        'thumbnail' => $streamIcon,
                        'direct_source' => '',
                    ];

                    $embyStats = $channel->getEmbyStreamStats();
                    if (! empty($embyStats)) {
                        $liveStream['stream_stats'] = $embyStats;
                    }

                    echo json_encode($liveStream);
                    $first = false;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
                echo ']';
            }, 200, [
                'Content-Type' => 'application/json',
                'X-Accel-Buffering' => 'no',
            ]);
        } elseif ($action === 'get_vod_streams') {
            // Network playlists don't have VOD streams
            if ($isNetworkPlaylist) {
                return response()->json([]);
            }

            $categoryId = $request->input('category_id');

            $channelsQuery = PlaylistGenerateController::getChannelQuery($playlist, isVod: true);

            if ($isCustomPlaylist) {
                $customPlaylistId = ($playlist instanceof PlaylistAlias) ? $playlist->custom_playlist_id : $playlist->id;
                $channelsQuery
                    ->selectRaw(
                        '(SELECT t.id FROM taggables tb INNER JOIN tags t ON t.id = tb.tag_id WHERE tb.taggable_id = channels.id AND tb.taggable_type = ? AND t.type = ? ORDER BY t.order_column ASC LIMIT 1) as custom_group_id',
                        [Channel::class, $tagUuid]
                    )
                    ->selectRaw(
                        '(SELECT ccp.channel_number FROM channel_custom_playlist ccp WHERE ccp.channel_id = channels.id AND ccp.custom_playlist_id = ?) as ccp_channel_number',
                        [$customPlaylistId]
                    );
            }

            if ($categoryId && $categoryId !== 'all') {
                if ($isCustomPlaylist) {
                    $channelsQuery->where(function ($query) use ($categoryId, $tagUuid) {
                        $query->whereHas('tags', function ($tagQuery) use ($categoryId, $tagUuid) {
                            $tagQuery->where('type', $tagUuid)
                                ->where('id', $categoryId);
                        })->orWhere(function ($subQuery) use ($categoryId, $tagUuid) {
                            $subQuery->whereDoesntHave('tags', function ($tagQuery) use ($tagUuid) {
                                $tagQuery->where('type', $tagUuid);
                            })->where('group_id', $categoryId);
                        });
                    });
                } else {
                    $channelsQuery->where('group_id', $categoryId);
                }
            }

            $cursor = $channelsQuery->cursor();
            $vodFileNameService = app(VodFileNameService::class);

            return response()->stream(function () use ($cursor, $playlist, $baseUrl, $isCustomPlaylist, $vodFileNameService) {
                $num = 0;
                $idChannelBy = $playlist->id_channel_by;
                $channelNumber = $playlist->auto_channel_increment ? $playlist->channel_start - 1 : 0;
                echo '[';
                $first = true;
                foreach ($cursor as $channel) {
                    if (! $first) {
                        echo ',';
                    }
                    $num++;

                    $streamIcon = $baseUrl.'/placeholder.png';
                    if ($channel->logo) {
                        $streamIcon = $channel->logo;
                    } elseif ($channel->logo_type === ChannelLogoType::Epg && $channel->epg_icon) {
                        $streamIcon = $channel->epg_icon;
                    } elseif ($channel->logo_type === ChannelLogoType::Channel && ($channel->logo || $channel->logo_internal)) {
                        $logo = $channel->logo ?? $channel->logo_internal ?? '';
                        $streamIcon = filter_var($logo, FILTER_VALIDATE_URL) ? $logo : $baseUrl."/$logo";
                    }
                    if ($playlist->enable_logo_proxy && filter_var($streamIcon, FILTER_VALIDATE_URL) && ! str_starts_with($streamIcon, url('/'))) {
                        $streamIcon = LogoProxyController::generateProxyUrl($streamIcon);
                    }

                    $channelCategoryId = 'all';
                    if ($isCustomPlaylist) {
                        if (! empty($channel->custom_group_id)) {
                            $channelCategoryId = (string) $channel->custom_group_id;
                        } elseif ($channel->group_id) {
                            $channelCategoryId = (string) $channel->group_id;
                        }
                    } elseif ($channel->group_id) {
                        $channelCategoryId = (string) $channel->group_id;
                    }

                    $tmdb = $channel->info['tmdb_id'] ?? $channel->movie_data['tmdb_id'] ?? 0;
                    $vodChannelNo = ($isCustomPlaylist && ! empty($channel->ccp_channel_number))
                        ? (int) $channel->ccp_channel_number
                        : $channel->channel;
                    if (! $vodChannelNo && ($playlist->auto_channel_increment || $idChannelBy === PlaylistChannelId::Number)) {
                        $vodChannelNo = ++$channelNumber;
                    }

                    echo json_encode([
                        'num' => $vodChannelNo,
                        'name' => $channel->title_custom ?? $channel->title,
                        'title' => $channel->title_custom ?? $channel->title,
                        'year' => $vodFileNameService->resolveMovieYearAsInt($channel),
                        'stream_type' => 'movie',
                        'stream_id' => $channel->id,
                        'stream_icon' => $streamIcon,
                        'rating' => $channel->rating ?? '',
                        'rating_5based' => $channel->rating_5based ?? 0,
                        'added' => (string) $channel->created_at->timestamp,
                        'category_id' => $channelCategoryId,
                        'category_ids' => [(int) $channelCategoryId],
                        'tmdb' => (string) $tmdb,
                        'tmdb_id' => (int) $tmdb,
                        'container_extension' => $channel->container_extension ?? 'mkv',
                        'custom_sid' => $channel->stream_id_custom ?? '',
                        'direct_source' => '',
                    ]);
                    $first = false;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
                echo ']';
            }, 200, [
                'Content-Type' => 'application/json',
                'X-Accel-Buffering' => 'no',
            ]);
        } elseif ($action === 'get_series') {
            // Network playlists don't have series
            if ($isNetworkPlaylist) {
                return response()->json([]);
            }

            $categoryId = $request->input('category_id');

            $seriesQuery = $playlist->series()
                ->where('series.enabled', true)
                ->orderBy('series.sort', 'asc')
                ->with(['tags', 'category']);

            // Apply category filtering if category_id is provided
            if ($categoryId && $categoryId !== 'all') {
                if ($isCustomPlaylist) {
                    // For CustomPlaylist, filter by tag ID or group_id
                    $seriesQuery->where(function ($query) use ($categoryId, $tagUuid) {
                        // Channels with custom tags matching the category ID
                        $query->whereHas('tags', function ($tagQuery) use ($categoryId, $tagUuid) {
                            $tagQuery->where('type', $tagUuid.'-category')
                                ->where('id', $categoryId);
                        })
                            // OR channels without custom tags but with matching group_id
                            ->orWhere(function ($subQuery) use ($categoryId, $tagUuid) {
                                $subQuery->whereDoesntHave('tags', function ($tagQuery) use ($tagUuid) {
                                    $tagQuery->where('type', $tagUuid.'-category');
                                })->where('category_id', $categoryId);
                            });
                    });
                } else {
                    // For regular Playlist and MergedPlaylist, filter by category_id
                    $seriesQuery->where('category_id', $categoryId);
                }
            }

            // Keyset pagination: compound (sort, id) cursor avoids the O(n²) offset
            // degradation of lazy() while still delivering correct sort order.
            $seriesIterable = PlaylistGenerateController::seriesKeysetLazy($seriesQuery, 500);

            // Custom playlists need tag-based ordering — materialise to sort, then stream.
            if ($isCustomPlaylist) {
                $categoryTagType = $tagUuid.'-category';
                $seriesIterable = $seriesIterable->collect()->sort(function ($a, $b) use ($categoryTagType) {
                    $aTag = $a->tags->where('type', $categoryTagType)->first();
                    $bTag = $b->tags->where('type', $categoryTagType)->first();

                    $aOrder = $aTag ? ($aTag->order_column ?? 999999) : ($a->category->sort_order ?? 999999);
                    $bOrder = $bTag ? ($bTag->order_column ?? 999999) : ($b->category->sort_order ?? 999999);

                    if ($aOrder !== $bOrder) {
                        return $aOrder <=> $bOrder;
                    }

                    $aSort = $a->pivot?->sort ?? $a->sort ?? 999999;
                    $bSort = $b->pivot?->sort ?? $b->sort ?? 999999;
                    if ($aSort !== $bSort) {
                        return $aSort <=> $bSort;
                    }

                    return ($a->name ?? '') <=> ($b->name ?? '');
                });
            }

            return response()->stream(function () use ($seriesIterable, $playlist, $baseUrl, $isCustomPlaylist, $tagUuid) {
                $num = 0;
                echo '[';
                $first = true;
                foreach ($seriesIterable as $seriesItem) {
                    if (! $first) {
                        echo ',';
                    }
                    $num++;

                    $seriesCategoryId = 'all';
                    if ($isCustomPlaylist) {
                        $customCat = $seriesItem->tags->where('type', $tagUuid.'-category')->first();
                        if ($customCat) {
                            $seriesCategoryId = (string) $customCat->id;
                        } elseif ($seriesItem->category_id) {
                            $seriesCategoryId = (string) $seriesItem->category_id;
                        }
                    } elseif ($seriesItem->category_id) {
                        $seriesCategoryId = (string) $seriesItem->category_id;
                    }

                    $tmdb = $seriesItem->metadata['tmdb_id'] ?? $seriesItem->metadata['tmdb'] ?? $seriesItem->tmdb_id ?? '';
                    $lastModified = $seriesItem->last_modified?->timestamp
                        ?? (isset($seriesItem->metadata['last_modified']) ? (int) $seriesItem->metadata['last_modified'] : null);

                    $cover = $seriesItem->cover
                        ? (filter_var($seriesItem->cover, FILTER_VALIDATE_URL) ? $seriesItem->cover : $baseUrl."/$seriesItem->cover")
                        : LogoCacheService::getPlaceholderUrl('poster');
                    $backdropPaths = $seriesItem->backdrop_path ?? [];
                    if (is_string($backdropPaths)) {
                        $backdropPaths = json_decode($backdropPaths, true) ?? [];
                    }
                    $backdropPaths = array_filter($backdropPaths);
                    if ($playlist->enable_logo_proxy) {
                        $cover = $this->proxyImageUrl($cover);
                        $backdropPaths = array_map(fn ($path) => $this->proxyImageUrl($path), $backdropPaths);
                    }

                    echo json_encode([
                        'num' => $num,
                        'name' => $seriesItem->name,
                        'series_id' => (int) $seriesItem->id,
                        'cover' => $cover,
                        'plot' => $seriesItem->plot ?? '',
                        'cast' => $seriesItem->cast ?? '',
                        'director' => $seriesItem->director ?? '',
                        'genre' => $seriesItem->genre ?? '',
                        'releaseDate' => $seriesItem->release_date ?? '',
                        'last_modified' => (string) ($lastModified),
                        'rating' => (string) ($seriesItem->rating ?? 0),
                        'rating_5based' => round((floatval($seriesItem->rating ?? 0)) / 2, 1),
                        'backdrop_path' => $backdropPaths,
                        'tmdb' => (string) $tmdb,
                        'tmdb_id' => (int) ($tmdb ?: 0),
                        'youtube_trailer' => $seriesItem->youtube_trailer ?? '',
                        'episode_run_time' => (string) ($seriesItem->episode_run_time ?? 0),
                        'category_id' => $seriesCategoryId,
                    ]);
                    $first = false;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
                echo ']';
            }, 200, [
                'Content-Type' => 'application/json',
                'X-Accel-Buffering' => 'no',
            ]);
        } elseif ($action === 'get_series_info') {
            $seriesId = $request->input('series_id');

            if (! $seriesId) {
                return response()->json(['error' => 'series_id parameter is required for get_series_info action'], 400);
            }

            $seriesItem = $playlist->series()
                ->where('enabled', true)
                ->where('series.id', $seriesId)
                ->with(['seasons.episodes', 'category'])
                ->first();

            if (! $seriesItem) {
                return response()->json(['error' => 'Series not found or not enabled'], 404);
            }

            // Check if this is a media server integration series (already has metadata from sync)
            $isMediaServerSeries = ! empty($seriesItem->metadata['media_server_id'] ?? null);

            // DVR-generated series have no upstream Xtream source; skip the provider
            // fetch entirely to avoid calling getSeriesInfo(null) and corrupting the response.
            $isDvrSeries = $seriesItem->import_batch_no === 'dvr';

            // fetchMetadata() handles its own freshness check internally (comparing last_modified
            // against last_metadata_fetch). It returns null when no fetch was needed, false on
            // failure, or an episode count on success.
            if (! $isMediaServerSeries && ! $isDvrSeries) {
                $results = $seriesItem->fetchMetadata(sync: false);
                if ($results !== null && $results !== false) {
                    // Provider returned new data — reload the model with fresh relations
                    $seriesItem = $seriesItem->fresh(['seasons.episodes', 'category']) ?? $seriesItem;
                }
            }

            $cover = $seriesItem->cover ? (filter_var($seriesItem->cover, FILTER_VALIDATE_URL) ? $seriesItem->cover : $baseUrl."/$seriesItem->cover") : LogoCacheService::getPlaceholderUrl('poster');
            $backdropPaths = $seriesItem->backdrop_path ?? [];
            if (is_string($backdropPaths)) {
                $backdropPaths = json_decode($backdropPaths, true) ?? [];
            }
            $backdropPaths = array_filter($backdropPaths);
            if ($playlist->enable_logo_proxy) {
                $cover = $this->proxyImageUrl($cover);
                $backdropPaths = array_map(fn ($path) => $this->proxyImageUrl($path), $backdropPaths);
            }

            $now = Carbon::now();
            $tmdb = $seriesItem->metadata['tmdb_id'] ?? $seriesItem->metadata['tmdb'] ?? $seriesItem->tmdb_id ?? '';
            $lastModified = $seriesItem->last_modified?->timestamp ?? $seriesItem->metadata['last_modified'] ?? null;

            $seriesInfo = [
                'name' => $seriesItem->name,
                'cover' => $cover,
                'plot' => $seriesItem->plot ?? '',
                'cast' => $seriesItem->cast ?? '',
                'director' => $seriesItem->director ?? '',
                'genre' => $seriesItem->genre ?? '',
                'releaseDate' => $seriesItem->release_date ?? '',
                'last_modified' => (string) $lastModified,
                'rating' => (string) ($seriesItem->rating ?? 0),
                'rating_5based' => round((floatval($seriesItem->rating ?? 0)) / 2, 1),
                'backdrop_path' => $backdropPaths,
                'tmdb' => (string) $tmdb,
                'tmdb_id' => (int) ($tmdb ?: 0),
                'youtube_trailer' => $seriesItem->youtube_trailer ?? '',
                'episode_run_time' => (string) ($seriesItem->episode_run_time ?? 0),
                'category_id' => (string) ($seriesItem->category_id ?? ($seriesItem->category ? $seriesItem->category->id : 'all')),
            ];

            $seasons = [];
            $episodesBySeason = [];
            if ($seriesItem->seasons && $seriesItem->seasons->isNotEmpty()) {
                foreach ($seriesItem->seasons as $season) {
                    $seasonNumber = $season->season_number;
                    $seasonCover = $playlist->enable_logo_proxy && ($season->cover ?? false)
                        ? $this->proxyImageUrl($season->cover)
                        : $season->cover;
                    $tmdbCover = $playlist->enable_logo_proxy && ($seriesItem->metadata['cover_tmdb'] ?? false)
                        ? $this->proxyImageUrl($seriesItem->metadata['cover_tmdb'])
                        : ($seriesItem->metadata['cover_tmdb'] ?? null);
                    $coverBig = $playlist->enable_logo_proxy && ($season->cover_big ?? false)
                        ? $this->proxyImageUrl($season->cover_big)
                        : ($season->cover_big ?? null);
                    $seasons[] = [
                        'name' => $season->metadata['name'] ?? "Season {$seasonNumber}",
                        'episode_count' => $season->episode_count ?? 0,
                        'overview' => $season->metadata['overview'] ?? '',
                        'air_date' => $season->metadata['air_date'] ?? '',
                        'cover' => $seasonCover,
                        'cover_tmdb' => $tmdbCover,
                        'season_number' => (int) $seasonNumber,
                        'cover_big' => $coverBig,
                        'releaseDate' => $season->metadata['release_date'] ?? $season->metadata['releaseDate'] ?? $season->metadata['air_date'] ?? '',
                        'duration' => (string) ($season->metadata['duration'] ?? 0),
                    ];
                    $seasonEpisodes = [];
                    if ($season->episodes && $season->episodes->isNotEmpty()) {
                        $orderedEpisodes = $season->episodes->sortBy('episode_num');
                        foreach ($orderedEpisodes as $episode) {
                            $containerExtension = $episode->container_extension ?? 'mp4';
                            if ($episode->info['movie_image'] ?? false) {
                                $movieImage = $playlist->enable_logo_proxy
                                    ? $this->proxyImageUrl($episode->info['movie_image'])
                                    : $episode->info['movie_image'];
                            }
                            if ($episode->info['cover_big'] ?? false) {
                                $movieImage = $playlist->enable_logo_proxy
                                    ? $this->proxyImageUrl($episode->info['cover_big'])
                                    : $episode->info['cover_big'];
                            }

                            $seasonEpisodes[] = [
                                'id' => (string) $episode->id,
                                'episode_num' => $episode->episode_num,
                                'title' => $episode->title ?? "Episode {$episode->episode_num}",
                                'container_extension' => $containerExtension,
                                'info' => array_merge($episode->info ?? [], [
                                    'movie_image' => $movieImage ?? null,
                                    'cover_big' => $coverBig ?? null,
                                    'plot' => $episode->plot ?? null,
                                ]),
                                'added' => $episode->added,
                                'season' => $episode->season,
                                'custom_sid' => $episode->custom_sid ?? '',
                                'stream_id' => $episode->id,
                                'direct_source' => '',
                            ];
                        }
                    }
                    if (! empty($seasonEpisodes)) {
                        $episodesBySeason[$seasonNumber] = $seasonEpisodes;
                    }
                }
            }

            return response()->json([
                'info' => $seriesInfo,
                'episodes' => ! empty($episodesBySeason) ? $episodesBySeason : (object) [],
                'seasons' => $seasons,
            ]);
        } elseif ($action === 'get_live_categories') {
            // Handle network playlists - return a single "Networks" category
            if ($isNetworkPlaylist) {
                return $this->getNetworkLiveCategories($playlist);
            }

            $liveCategories = [];

            if ($isCustomPlaylist) {
                // For CustomPlaylist, get unique tags (groups) from channels with live content
                $channelIds = $playlist->channels()
                    ->where('enabled', true)
                    ->where('is_vod', false)
                    ->pluck('id');

                // Get custom tags assigned to channels
                $tags = $playlist->groupTags()
                    ->whereIn('id', function ($query) use ($channelIds) {
                        $query->select('tag_id')
                            ->from('taggables')
                            ->where('taggable_type', Channel::class)
                            ->whereIn('taggable_id', $channelIds);
                    })->get();

                // Sort tags by order_column
                $sortedTags = $tags->sortBy('order_column')->values();

                foreach ($sortedTags as $tag) {
                    $liveCategories[] = [
                        'category_id' => (string) $tag->id, // Use tag ID instead of name
                        'category_name' => $tag->name,
                        'parent_id' => 0,
                        'sort_order' => $tag->order_column ?? 999999,
                    ];
                }

                // Also get original groups for channels without custom tags (fallback)
                $channelsWithTags = Channel::whereIn('id', $channelIds)
                    ->whereHas('tags', function ($query) use ($tagUuid) {
                        $query->where('type', $tagUuid);
                    })
                    ->pluck('id');

                $channelsWithoutTags = $channelIds->diff($channelsWithTags);

                if ($channelsWithoutTags->isNotEmpty()) {
                    $fallbackGroups = Group::whereIn('id', function ($query) use ($channelsWithoutTags) {
                        $query->select('group_id')
                            ->from('channels')
                            ->whereIn('id', $channelsWithoutTags)
                            ->whereNotNull('group_id');
                    })->orderBy('sort_order')->get();

                    foreach ($fallbackGroups as $group) {
                        // Avoid duplicate category_ids
                        $existingIds = array_column($liveCategories, 'category_id');
                        if (! in_array((string) $group->id, $existingIds)) {
                            $liveCategories[] = [
                                'category_id' => (string) $group->id,
                                'category_name' => $group->name,
                                'parent_id' => 0,
                                'sort_order' => $group->sort_order ?? 999999,
                            ];
                        }
                    }
                }

                // Sort all categories by sort_order to ensure proper ordering
                usort($liveCategories, function ($a, $b) {
                    return ($a['sort_order'] ?? 999999) <=> ($b['sort_order'] ?? 999999);
                });

                // Remove sort_order from output
                $liveCategories = array_map(function ($cat) {
                    unset($cat['sort_order']);

                    return $cat;
                }, $liveCategories);

                // Custom playlist categories are the displayed tag/group names, so the alias
                // filter is applied to the built list rather than to the underlying queries.
                $liveCategories = self::filterCategoriesByName($liveCategories, $aliasLiveGroupFilter);
            } else {
                // For regular Playlist and MergedPlaylist, use the groups() relationship
                $groups = $playlist->groups()
                    ->orderBy('sort_order')
                    ->whereHas('channels', function ($query) use ($aliasLiveGroupFilter) {
                        $query->where('enabled', true)
                            ->where('is_vod', false);
                        if (! empty($aliasLiveGroupFilter)) {
                            $query->whereIn('group_internal', $aliasLiveGroupFilter);
                        }
                    })
                    ->get();

                foreach ($groups as $group) {
                    $liveCategories[] = [
                        'category_id' => (string) $group->id,
                        'category_name' => $group->name,
                        'parent_id' => 0,
                    ];
                }
            }

            // Add a default "All" category if no specific groups exist
            if (empty($liveCategories)) {
                $liveCategories[] = [
                    'category_id' => 'all',
                    'category_name' => 'All',
                    'parent_id' => 0,
                ];
            }

            return response()->json($liveCategories);
        } elseif ($action === 'get_vod_categories') {
            // Network playlists don't have VOD categories
            if ($isNetworkPlaylist) {
                return response()->json([]);
            }

            $vodCategories = [];

            if ($isCustomPlaylist) {
                // For CustomPlaylist, get unique tags (groups) from channels with VOD content
                $channelIds = $playlist->channels()
                    ->where('enabled', true)
                    ->where('is_vod', true)
                    ->pluck('id');

                // Get custom tags assigned to channels
                $tags = $playlist->groupTags()
                    ->whereIn('id', function ($query) use ($channelIds) {
                        $query->select('tag_id')
                            ->from('taggables')
                            ->where('taggable_type', Channel::class)
                            ->whereIn('taggable_id', $channelIds);
                    })->get();

                // Sort tags by order_column
                $sortedTags = $tags->sortBy('order_column')->values();

                foreach ($sortedTags as $tag) {
                    $vodCategories[] = [
                        'category_id' => (string) $tag->id, // Use tag ID instead of name
                        'category_name' => $tag->name,
                        'parent_id' => 0,
                        'sort_order' => $tag->order_column ?? 999999,
                    ];
                }

                // Also get original groups for channels without custom tags (fallback)
                $channelsWithTags = Channel::whereIn('id', $channelIds)
                    ->whereHas('tags', function ($query) use ($tagUuid) {
                        $query->where('type', $tagUuid);
                    })
                    ->pluck('id');

                $channelsWithoutTags = $channelIds->diff($channelsWithTags);

                if ($channelsWithoutTags->isNotEmpty()) {
                    $fallbackGroups = Group::whereIn('id', function ($query) use ($channelsWithoutTags) {
                        $query->select('group_id')
                            ->from('channels')
                            ->whereIn('id', $channelsWithoutTags)
                            ->whereNotNull('group_id');
                    })->orderBy('sort_order')->get();

                    foreach ($fallbackGroups as $group) {
                        // Avoid duplicate category_ids
                        $existingIds = array_column($vodCategories, 'category_id');
                        if (! in_array((string) $group->id, $existingIds)) {
                            $vodCategories[] = [
                                'category_id' => (string) $group->id,
                                'category_name' => $group->name,
                                'parent_id' => 0,
                                'sort_order' => $group->sort_order ?? 999999,
                            ];
                        }
                    }
                }

                // Sort all categories by sort_order to ensure proper ordering
                usort($vodCategories, function ($a, $b) {
                    return ($a['sort_order'] ?? 999999) <=> ($b['sort_order'] ?? 999999);
                });

                // Remove sort_order from output
                $vodCategories = array_map(function ($cat) {
                    unset($cat['sort_order']);

                    return $cat;
                }, $vodCategories);

                $vodCategories = self::filterCategoriesByName($vodCategories, $aliasVodGroupFilter);
            } else {
                // For regular Playlist and MergedPlaylist, use the groups() relationship
                $vodGroups = $playlist->groups()
                    ->orderBy('sort_order')
                    ->whereHas('channels', function ($query) use ($aliasVodGroupFilter) {
                        $query->where('enabled', true)
                            ->where('is_vod', true);
                        if (! empty($aliasVodGroupFilter)) {
                            $query->whereIn('group_internal', $aliasVodGroupFilter);
                        }
                    })
                    ->get();

                foreach ($vodGroups as $group) {
                    $vodCategories[] = [
                        'category_id' => (string) $group->id,
                        'category_name' => $group->name,
                        'parent_id' => 0,
                    ];
                }
            }

            // Add a default "All" category if no specific categories exist
            if (empty($vodCategories)) {
                $vodCategories[] = [
                    'category_id' => 'all',
                    'category_name' => 'All',
                    'parent_id' => 0,
                ];
            }

            return response()->json($vodCategories);
        } elseif ($action === 'get_series_categories') {
            // Network playlists don't have series categories
            if ($isNetworkPlaylist) {
                return response()->json([]);
            }

            $seriesCategories = [];

            if ($isCustomPlaylist) {
                // For CustomPlaylist, get unique tags (categories) from series
                $seriesIds = $playlist->series()
                    ->where('enabled', true)
                    ->pluck('id');

                // Get custom tags assigned to series
                $tags = $playlist->categoryTags()
                    ->whereIn('id', function ($query) use ($seriesIds) {
                        $query->select('tag_id')
                            ->from('taggables')
                            ->where('taggable_type', Series::class)
                            ->whereIn('taggable_id', $seriesIds);
                    })->get();

                // Sort tags by order_column
                $sortedTags = $tags->sortBy('order_column')->values();

                foreach ($sortedTags as $tag) {
                    $seriesCategories[] = [
                        'category_id' => (string) $tag->id, // Use tag ID instead of name
                        'category_name' => $tag->name,
                        'parent_id' => 0,
                        'sort_order' => $tag->order_column ?? 999999,
                    ];
                }

                // Also get original categories for series without custom tags (fallback)
                $seriesWithTags = Series::whereIn('id', $seriesIds)
                    ->whereHas('tags', function ($query) use ($tagUuid) {
                        $query->where('type', $tagUuid.'-category');
                    })
                    ->pluck('id');

                $seriesWithoutTags = $seriesIds->diff($seriesWithTags);

                if ($seriesWithoutTags->isNotEmpty()) {
                    $fallbackCategories = Category::whereIn('id', function ($query) use ($seriesWithoutTags) {
                        $query->select('category_id')
                            ->from('series')
                            ->whereIn('id', $seriesWithoutTags)
                            ->whereNotNull('category_id');
                    })->orderBy('sort_order')->get();

                    foreach ($fallbackCategories as $category) {
                        // Avoid duplicate category_ids
                        $existingIds = array_column($seriesCategories, 'category_id');
                        if (! in_array((string) $category->id, $existingIds)) {
                            $seriesCategories[] = [
                                'category_id' => (string) $category->id,
                                'category_name' => $category->name,
                                'parent_id' => 0,
                                'sort_order' => $category->sort_order ?? 999999,
                            ];
                        }
                    }
                }

                // Sort all categories by sort_order to ensure proper ordering
                usort($seriesCategories, function ($a, $b) {
                    return ($a['sort_order'] ?? 999999) <=> ($b['sort_order'] ?? 999999);
                });

                // Remove sort_order from output
                $seriesCategories = array_map(function ($cat) {
                    unset($cat['sort_order']);

                    return $cat;
                }, $seriesCategories);

                $seriesCategories = self::filterCategoriesByName($seriesCategories, $aliasCategoryFilter);
            } else {
                // Get categories from series only — the series() relationship on PlaylistAlias
                // automatically applies any alias category filter, so no extra scoping needed.
                $categories = $playlist->series()
                    ->where('enabled', true)
                    ->with('category')
                    ->get()
                    ->pluck('category')
                    ->filter()
                    ->unique('id')
                    ->sortBy('sort_order');

                foreach ($categories as $category) {
                    $seriesCategories[] = [
                        'category_id' => (string) $category->id,
                        'category_name' => $category->name,
                        'parent_id' => 0,
                    ];
                }
            }

            // Add a default "All" category if no specific categories exist
            if (empty($seriesCategories)) {
                $seriesCategories[] = [
                    'category_id' => 'all',
                    'category_name' => 'All',
                    'parent_id' => 0,
                ];
            }

            return response()->json($seriesCategories);
        } elseif ($action === 'get_vod_info') {
            $channelId = $request->input('vod_id');

            if (! $channelId || ! is_numeric($channelId)) {
                return response()->json(['error' => 'vod_id parameter is required for get_vod_info action'], 400);
            }

            $channelId = (int) $channelId;

            // Find the channel
            $channel = $playlist->channels()
                ->where('enabled', true)
                ->where('channels.id', $channelId)
                ->where('is_vod', true)
                ->first();

            if (! $channel) {
                return response()->json(['error' => 'VOD not found'], 404);
            }

            // Check if VOD metadata has been fetched
            if (! $channel->last_metadata_fetch) {
                // No metadata, fetch it!
                $results = $channel->fetchMetadata();
                if ($results === false) {
                    return response()->json(['error' => 'Failed to fetch VOD metadata'], 500);
                }
            }

            // Build info section - use channel's info field if available, otherwise build from channel data
            $info = $channel->info ?? [];

            $cover = $info['cover_big'] ?? $channel->logo ?? $channel->logo_internal;
            $movieImage = $info['movie_image'] ?? $channel->logo ?? $channel->logo_internal;
            $backdropPaths = $info['backdrop_path'] ?? [];
            if (is_string($backdropPaths)) {
                $backdropPaths = json_decode($backdropPaths, true) ?? [];
            }
            $backdropPaths = array_filter($backdropPaths);
            if ($playlist->enable_logo_proxy) {
                $cover = $this->proxyImageUrl($cover);
                $movieImage = $this->proxyImageUrl($movieImage);
                $backdropPaths = array_map(fn ($path) => $this->proxyImageUrl($path), $backdropPaths);
            }

            // Fill in missing info fields with channel data
            $defaultInfo = [
                'kinopoisk_url' => $info['kinopoisk_url'] ?? '',
                'tmdb_id' => $channel->getTmdbId() ?? 0,
                'name' => $info['name'] ?? $channel->name,
                'o_name' => $info['o_name'] ?? $channel->name,
                'cover_big' => $cover,
                'movie_image' => $movieImage,
                'release_date' => $info['release_date'] ?? $channel->year,
                'episode_run_time' => $info['episode_run_time'] ?? 0,
                'youtube_trailer' => $info['youtube_trailer'] ?? null,
                'director' => $info['director'] ?? '',
                'actors' => $info['actors'] ?? '',
                'cast' => $info['cast'] ?? '',
                'description' => $info['description'] ?? '',
                'plot' => $info['plot'] ?? '',
                'age' => $info['age'] ?? '',
                'mpaa_rating' => $info['mpaa_rating'] ?? '',
                'rating_count_kinopoisk' => $info['rating_count_kinopoisk'] ?? 0,
                'country' => $info['country'] ?? '',
                'genre' => $info['genre'] ?? '',
                'backdrop_path' => $backdropPaths,
                'duration_secs' => $info['duration_secs'] ?? 0,
                'duration' => $info['duration'] ?? '00:00:00',
                'bitrate' => $info['bitrate'] ?? 0,
                'rating' => $channel->rating ?? $info['rating'],
                'releasedate' => $info['releasedate'] ?? $channel->year,
                'subtitles' => $info['subtitles'] ?? [],
            ];

            // Build movie_data section - use channel's movie_data field if available, otherwise build from channel data
            $movieData = $channel->movie_data ?? [];

            $extension = $movieData['container_extension'] ?? $channel->container_extension ?? 'mp4';
            $defaultMovieData = [
                'stream_id' => $channel->id,
                'name' => $movieData['name'] ?? $channel->name,
                'title' => $movieData['title'] ?? $channel->name,
                'year' => app(VodFileNameService::class)->resolveMovieYearAsInt($channel),
                'added' => $movieData['added'] ?? (string) ($channel->created_at ? $channel->created_at->timestamp : time()),
                'category_id' => (string) ($channel->group_id ?? ''),
                'category_ids' => ($channel->group_id ? [(int) $channel->group_id] : []),
                'container_extension' => $extension,
                'custom_sid' => $movieData['custom_sid'] ?? '',
                'direct_source' => '',
            ];

            // Return response with metadata at BOTH root level (for compatibility with buggy players
            // like Another IPTV Player that read from root) AND in standard 'info'/'movie_data' objects
            // (for properly implemented Xtream API clients)
            return response()->json(array_merge($defaultInfo, [
                'info' => $defaultInfo,
                'movie_data' => $defaultMovieData,
            ]));
        } elseif ($action === 'get_short_epg') {
            // Handle network playlists - return EPG from network schedule
            if ($isNetworkPlaylist) {
                return $this->getNetworkShortEpg($playlist, $request);
            }

            $streamId = $request->input('stream_id');
            $limit = $request->input('limit');
            $limit = (int) ($limit ?? 4);
            $proxyEnabled = $playlist->enable_proxy;

            if (! $streamId) {
                return response()->json(['error' => 'stream_id parameter is required for get_short_epg action'], 400);
            }

            // Find the channel
            $channel = $playlist->channels()
                ->where('enabled', true)
                ->where('channels.id', $streamId)
                ->with('epgChannel')
                ->first();

            if (! $channel) {
                return response()->json(['error' => 'Channel not found'], 404);
            }

            if (! $channel->epgChannel) {
                return response()->json(['epg_listings' => []]);
            }

            // Get EPG data using EpgCacheService
            $cacheService = new EpgCacheService;
            $epg = Epg::find($channel->epgChannel->epg_id);

            if (! $epg || ! $epg->is_cached) {
                return response()->json(['epg_listings' => []]);
            }

            // Get programmes for today and tomorrow to ensure we have enough data
            $today = Carbon::now()->format('Y-m-d');
            $tomorrow = Carbon::now()->addDay()->format('Y-m-d');

            $todayProgrammes = $cacheService->getCachedProgrammes($epg, $today, [$channel->epgChannel->channel_id]);
            $tomorrowProgrammes = $cacheService->getCachedProgrammes($epg, $tomorrow, [$channel->epgChannel->channel_id]);

            $allProgrammes = [];
            if (isset($todayProgrammes[$channel->epgChannel->channel_id])) {
                $allProgrammes = array_merge($allProgrammes, $todayProgrammes[$channel->epgChannel->channel_id]);
            }
            if (isset($tomorrowProgrammes[$channel->epgChannel->channel_id])) {
                $allProgrammes = array_merge($allProgrammes, $tomorrowProgrammes[$channel->epgChannel->channel_id]);
            }

            // Check if channel is currently playing
            $isNowPlaying = $proxyEnabled ? M3uProxyService::isChannelActive($channel) : false;

            // Filter programmes to current time and future, then limit
            $now = Carbon::now();
            $epgListings = [];
            $count = 0;

            foreach ($allProgrammes as $programme) {
                if ($count >= $limit) {
                    break;
                }

                $startTime = Carbon::parse($programme['start']);
                $endTime = Carbon::parse($programme['stop']);

                // Include current programme and future programmes
                if ($endTime->gt($now)) {
                    $isCurrentProgramme = $startTime->lte($now) && $endTime->gt($now);

                    $epgListings[] = [
                        'id' => (string) ($programme['id'] ?? $count),
                        'epg_id' => (string) $epg->id,
                        'title' => $programme['title'] ?? '',
                        'subtitle' => $programme['subtitle'] ?? '',
                        'lang' => $programme['lang'] ?? 'en',
                        'start' => $startTime->format('Y-m-d H:i:s'),
                        'end' => $endTime->format('Y-m-d H:i:s'),
                        'description' => $programme['desc'] ?? '',
                        'channel_id' => $channel->epgChannel->channel_id,
                        'start_timestamp' => (string) $startTime->timestamp,
                        'stop_timestamp' => (string) $endTime->timestamp,
                        'now_playing' => ($isCurrentProgramme && $isNowPlaying) ? 1 : 0,
                        'has_archive' => (! $disableCatchup && $channel->catchup && $endTime->lt($now)) ? 1 : 0,
                    ];
                    $count++;
                }
            }

            return response()->json(['epg_listings' => $epgListings]);
        } elseif ($action === 'get_simple_data_table') {
            // Handle network playlists - return EPG from network schedule
            if ($isNetworkPlaylist) {
                return $this->getNetworkSimpleDataTable($playlist, $request);
            }

            $streamId = $request->input('stream_id');
            $proxyEnabled = $playlist->enable_proxy;

            if (! $streamId) {
                return response()->json(['error' => 'stream_id parameter is required for get_simple_data_table action'], 400);
            }

            // Find the channel
            $channel = $playlist->channels()
                ->where('enabled', true)
                ->where('channels.id', $streamId)
                ->with('epgChannel')
                ->first();

            if (! $channel) {
                return response()->json(['error' => 'Channel not found'], 404);
            }

            if (! $channel->epgChannel) {
                return response()->json(['epg_listings' => []]);
            }

            // Get EPG data using EpgCacheService
            $cacheService = new EpgCacheService;
            $epg = Epg::find($channel->epgChannel->epg_id);

            if (! $epg || ! $epg->is_cached) {
                return response()->json(['epg_listings' => []]);
            }

            // Get programmes for several days to ensure we have enough data
            // Start from 4 days ago to cover past programmes as well
            // We fetch 8 days total (4 past, today, 3 future)
            $daysToFetch = 8;
            $allProgrammes = [];
            $threeDaysAgo = Carbon::now()->subDays(value: 4);
            foreach (range(0, $daysToFetch - 1) as $dayOffset) {
                $date = $threeDaysAgo->clone()->addDays($dayOffset)->format('Y-m-d');
                $programmes = $cacheService->getCachedProgrammes($epg, $date, [$channel->epgChannel->channel_id]);
                if (isset($programmes[$channel->epgChannel->channel_id])) {
                    $allProgrammes = array_merge($allProgrammes, $programmes[$channel->epgChannel->channel_id]);
                }
            }

            $epgListings = [];
            if (! empty($allProgrammes)) {
                // Check if channel is currently playing
                $isNowPlaying = $proxyEnabled ? M3uProxyService::isChannelActive($channel) : false;

                $now = Carbon::now();
                foreach ($allProgrammes as $index => $programme) {
                    $startTime = Carbon::parse($programme['start']);
                    $endTime = Carbon::parse($programme['stop']);
                    $isCurrentProgramme = $startTime->lte($now) && $endTime->gt($now);

                    $epgListings[] = [
                        'id' => (string) ($programme['id'] ?? $index),
                        'epg_id' => (string) $epg->id,
                        'title' => base64_encode($programme['title'] ?? ''),
                        'description' => base64_encode($programme['desc'] ?? ''),
                        'lang' => $programme['lang'] ?? 'en',
                        'start' => $startTime->format('Y-m-d H:i:s'),
                        'end' => $endTime->format('Y-m-d H:i:s'),
                        'channel_id' => $channel->epgChannel->channel_id,
                        'start_timestamp' => (string) $startTime->timestamp,
                        'stop_timestamp' => (string) $endTime->timestamp,
                        'now_playing' => ($isCurrentProgramme && $isNowPlaying) ? 1 : 0,
                        'has_archive' => (! $disableCatchup && $channel->catchup && $endTime->lt($now)) ? 1 : 0,
                    ];
                }
            }

            return response()->json(['epg_listings' => $epgListings]);
        } elseif ($action === 'get_epg_batch') {
            // Batch EPG endpoint - fetches EPG for multiple channels in a single request
            if ($isNetworkPlaylist) {
                return response()->json(['error' => 'Batch EPG not supported for network playlists'], 400);
            }

            $streamIdsParam = $request->input('stream_ids');
            if (! $streamIdsParam) {
                return response()->json(['error' => 'stream_ids parameter is required'], 400);
            }

            $streamIds = array_map('intval', explode(',', $streamIdsParam));
            $streamIds = array_slice($streamIds, 0, 100);

            $date = $request->input('date', Carbon::now()->format('Y-m-d'));
            $proxyEnabled = $playlist->enable_proxy;

            // Load all requested channels in one query
            $channels = $playlist->channels()
                ->where('enabled', true)
                ->whereIn('channels.id', $streamIds)
                ->with('epgChannel')
                ->get()
                ->keyBy('id');

            // Group channels by EPG source so each JSONL file is read once
            $epgGroups = [];
            foreach ($channels as $channel) {
                if (! $channel->epgChannel) {
                    continue;
                }
                $epgId = $channel->epgChannel->epg_id;
                if (! isset($epgGroups[$epgId])) {
                    $epg = Epg::find($epgId);
                    if (! $epg || ! $epg->is_cached) {
                        continue;
                    }
                    $epgGroups[$epgId] = ['epg' => $epg, 'channelMap' => []];
                }
                $epgGroups[$epgId]['channelMap'][$channel->id] = $channel->epgChannel->channel_id;
            }

            $cacheService = new EpgCacheService;
            $now = Carbon::now();
            $nextDate = Carbon::parse($date)->addDay()->format('Y-m-d');
            $result = [];

            foreach ($epgGroups as $group) {
                $epg = $group['epg'];
                $epgChannelIds = array_values($group['channelMap']);

                // Fetch requested date + next day to cover timezone differences
                $programmes = $cacheService->getCachedProgrammes($epg, $date, $epgChannelIds);
                $nextDayProgrammes = $cacheService->getCachedProgrammes($epg, $nextDate, $epgChannelIds);

                // Merge next day's programmes into the main set
                foreach ($nextDayProgrammes as $channelId => $progs) {
                    if (! isset($programmes[$channelId])) {
                        $programmes[$channelId] = [];
                    }
                    $programmes[$channelId] = array_merge($programmes[$channelId], $progs);
                }

                foreach ($group['channelMap'] as $streamId => $epgChannelId) {
                    $channelProgrammes = $programmes[$epgChannelId] ?? [];
                    $channel = $channels[$streamId];

                    // Fill gaps in EPG
                    if (empty($channelProgrammes)) {
                        $start = Carbon::parse($date)->startOfDay();
                        $end = Carbon::parse($nextDate)->endOfDay();

                        $current = $start->copy();
                        while ($current->lt($end)) {
                            $chunkEnd = $current->copy()->addHour();
                            if ($chunkEnd->gt($end)) {
                                $chunkEnd = $end->copy();
                            }

                            $channelProgrammes[] = [
                                'id' => 'dummy-'.md5($streamId.$current->timestamp),
                                'title' => $channel->name ?? 'Unknown Channel',
                                'desc' => 'No information available',
                                'start' => $current->format('Y-m-d H:i:s'),
                                'stop' => $chunkEnd->format('Y-m-d H:i:s'),
                                'lang' => 'en',
                            ];
                            $current = $chunkEnd;
                        }
                    } else {
                        usort($channelProgrammes, function ($a, $b) {
                            return strcmp($a['start'], $b['start']);
                        });

                        $filled = [];
                        $lastEnd = Carbon::parse($date)->startOfDay();
                        $finalEnd = Carbon::parse($nextDate)->endOfDay();

                        foreach ($channelProgrammes as $prog) {
                            $start = Carbon::parse($prog['start']);
                            $stop = Carbon::parse($prog['stop']);

                            if ($start->gt($lastEnd) && $start->diffInMinutes($lastEnd) > 1) {
                                $gapStart = $lastEnd->copy();
                                while ($gapStart->lt($start)) {
                                    $gapEnd = $gapStart->copy()->addHour();
                                    if ($gapEnd->gt($start)) {
                                        $gapEnd = $start->copy();
                                    }

                                    $filled[] = [
                                        'id' => 'dummy-'.md5($streamId.$gapStart->timestamp),
                                        'title' => $channel->name ?? 'Unknown Channel',
                                        'desc' => 'No information available',
                                        'start' => $gapStart->format('Y-m-d H:i:s'),
                                        'stop' => $gapEnd->format('Y-m-d H:i:s'),
                                        'lang' => 'en',
                                    ];
                                    $gapStart = $gapEnd;
                                }
                            }

                            $filled[] = $prog;

                            if ($stop->gt($lastEnd)) {
                                $lastEnd = $stop;
                            }
                        }

                        if ($finalEnd->gt($lastEnd) && $finalEnd->diffInMinutes($lastEnd) > 1) {
                            $gapStart = $lastEnd->copy();
                            while ($gapStart->lt($finalEnd)) {
                                $gapEnd = $gapStart->copy()->addHour();
                                if ($gapEnd->gt($finalEnd)) {
                                    $gapEnd = $finalEnd->copy();
                                }

                                $filled[] = [
                                    'id' => 'dummy-'.md5($streamId.$gapStart->timestamp),
                                    'title' => $channel->name ?? 'Unknown Channel',
                                    'desc' => 'No information available',
                                    'start' => $gapStart->format('Y-m-d H:i:s'),
                                    'stop' => $gapEnd->format('Y-m-d H:i:s'),
                                    'lang' => 'en',
                                ];
                                $gapStart = $gapEnd;
                            }
                        }
                        $channelProgrammes = $filled;
                    }

                    $isNowPlaying = $proxyEnabled ? M3uProxyService::isChannelActive($channel) : false;

                    $epgListings = [];
                    foreach ($channelProgrammes as $index => $programme) {
                        $startTime = Carbon::parse($programme['start']);
                        $endTime = Carbon::parse($programme['stop']);
                        $isCurrentProgramme = $startTime->lte($now) && $endTime->gt($now);

                        $epgListings[] = [
                            'id' => (string) ($programme['id'] ?? $index),
                            'epg_id' => (string) $epg->id,
                            'title' => base64_encode($programme['title'] ?? ''),
                            'subtitle' => base64_encode($programme['subtitle'] ?? ''),
                            'description' => base64_encode($programme['desc'] ?? ''),
                            'lang' => $programme['lang'] ?? 'en',
                            'start' => $startTime->format('Y-m-d H:i:s'),
                            'end' => $endTime->format('Y-m-d H:i:s'),
                            'channel_id' => $epgChannelId,
                            'start_timestamp' => (string) $startTime->timestamp,
                            'stop_timestamp' => (string) $endTime->timestamp,
                            'now_playing' => ($isCurrentProgramme && $isNowPlaying) ? 1 : 0,
                            'has_archive' => (! $disableCatchup && $channel->catchup && $endTime->lt($now)) ? 1 : 0,
                        ];
                    }
                    $result[(string) $streamId] = ['epg_listings' => $epgListings];
                }
            }

            // Include empty results for channels without EPG data
            foreach ($streamIds as $sid) {
                if (! isset($result[(string) $sid])) {
                    $result[(string) $sid] = ['epg_listings' => []];
                }
            }

            return response()->json($result);
        } elseif ($action === 'm3u_plus') {
            // For m3u_plus, redirect to the m3u method which handles the request
            return $this->m3u($playlist);
        } elseif ($action === 'get_viewers') {
            return $this->getViewers($request, $playlist, $authMethod, $username, $password);
        } elseif ($action === 'create_viewer') {
            return $this->createViewer($request, $playlist);
        } elseif ($action === 'get_progress') {
            return $this->getProgress($request, $playlist, $authMethod, $username, $password);
        } elseif ($action === 'update_progress') {
            return $this->updateProgress($request, $playlist, $authMethod, $username, $password);
        } elseif ($action === 'get_series_progress') {
            return $this->getSeriesProgress($request, $playlist, $authMethod, $username, $password);
        } elseif ($action === 'get_recently_watched') {
            return $this->getRecentlyWatched($request, $playlist, $authMethod, $username, $password);
        } elseif ($action === 'get_favorites') {
            return $this->getFavorites($request, $playlist, $authMethod, $username, $password);
        } elseif ($action === 'toggle_favorite') {
            return $this->toggleFavorite($request, $playlist, $authMethod, $username, $password);
        } elseif ($action === 'sync_favorites') {
            return $this->syncFavorites($request, $playlist, $authMethod, $username, $password);
        } elseif ($action === 'request_search') {
            return $this->searchRequests($request, $playlist, $authMethod, $playlistAuth);
        } elseif ($action === 'request_submit') {
            return $this->submitRequest($request, $playlist, $authMethod, $playlistAuth);
        } elseif ($action === 'request_history') {
            return $this->requestHistory($request, $playlist, $authMethod, $playlistAuth);
        } elseif ($action === 'request_status') {
            return $this->requestStatus($request, $playlist, $authMethod, $playlistAuth);
        } elseif ($action === 'request_dismiss') {
            return $this->dismissRequest($request, $playlist, $authMethod, $playlistAuth);
        } elseif ($action === 'm3u_editor_register_publisher') {
            return $this->registerManagedLibraryPublisher($request, $playlist, $authMethod, $playlistAuth);
        } elseif ($action === 'm3u_editor_catalog') {
            return $this->managedLibraryCatalog($request, $playlist, $authMethod, $playlistAuth);
        } elseif ($action === 'm3u_editor_sync_result') {
            return $this->managedLibrarySyncResult($request, $playlist, $authMethod, $playlistAuth);
        } elseif (in_array($action, self::DVR_ACTIONS, true)) {
            $dvrPlaylist = $this->resolveDvrPlaylist($playlist);

            // Every DVR action must pass the same baseline gate used to decide
            // whether the DVR feature is advertised at all (global config, the
            // effective playlist's DvrSetting, and playlist_auth's dvr_enabled
            // flag) — a credential that was never shown DVR controls, or whose
            // playlist has DVR turned off, must not be able to dispatch any DVR
            // action directly regardless of what it individually checks.
            if (! $this->dvrCapabilityGranted($dvrPlaylist, $authMethod, $playlistAuth)) {
                return response()->json(['error' => 'DVR access denied'], 403);
            }

            return match ($action) {
                'get_dvr_recordings' => $this->getDvrRecordings($request, $dvrPlaylist, $username, $password, $playlistAuth),
                'get_dvr_recording' => $this->getDvrRecording($request, $dvrPlaylist, $username, $password, $playlistAuth),
                'get_dvr_storage' => $this->getDvrStorage($dvrPlaylist, $playlistAuth),
                'schedule_dvr' => $this->scheduleDvr($request, $dvrPlaylist, $playlistAuth),
                'create_dvr_series_rule' => $this->createDvrSeriesRule($request, $dvrPlaylist, $playlistAuth),
                'update_dvr_series_rule' => $this->updateDvrSeriesRule($request, $dvrPlaylist, $playlistAuth),
                'cancel_dvr_recording' => $this->cancelDvrRecording($request, $dvrPlaylist, $playlistAuth),
                'delete_dvr_recording' => $this->deleteDvrRecording($request, $dvrPlaylist, $playlistAuth),
                'list_dvr_series_rules' => $this->listDvrSeriesRules($request, $dvrPlaylist, $playlistAuth),
                'delete_dvr_series_rule' => $this->deleteDvrSeriesRule($request, $dvrPlaylist, $playlistAuth),
                'search_epg_shows' => $this->searchEpgShows($request, $dvrPlaylist, $playlistAuth),
            };
        } else {
            return response()->json(['error' => 'Invalid action parameter'], 400);
        }
    }

    /**
     * Redirects to the M3U playlist generation route.
     *
     * This method handles the M3U playlist request by calling the PlaylistGenerateController
     * with the appropriate playlist UUID.
     *
     * @param  mixed  $playlist  The authenticated playlist instance.
     * @return Response
     */
    public function m3u($playlist)
    {
        return app()->call(PlaylistGenerateController::class, [
            'uuid' => $playlist->uuid,
        ]);
    }

    /**
     * Redirects to the EPG generation route.
     *
     * This method handles the EPG request by authenticating the user and redirecting
     * to the appropriate EPG generation URL based on the playlist UUID.
     *
     * @return Response|JsonResponse
     */
    public function epg(Request $request)
    {
        // Authenticate the user based on the provided credentials
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);
        [$playlist, $authMethod, $username, $password] = $this->authenticate($request);

        // If no authentication method worked, return error
        if (! $playlist || $authMethod === 'none') {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Serve EPG directly instead of redirecting, so it works on the Xtream-only port
        return app()->call(EpgGenerateController::class, [
            'uuid' => $playlist->uuid,
        ]);
    }

    /**
     * Restrict a built category list to the names allowed by an alias's channel filter.
     *
     * @param  array<int, array<string, mixed>>  $categories
     * @param  array<string>  $allowedNames  An empty list means no restriction.
     * @return array<int, array<string, mixed>>
     */
    private static function filterCategoriesByName(array $categories, array $allowedNames): array
    {
        if (empty($allowedNames)) {
            return $categories;
        }

        return array_values(array_filter(
            $categories,
            fn (array $category): bool => in_array($category['category_name'], $allowedNames, true)
        ));
    }

    /**
     * Get live streams for a network playlist.
     * Each network becomes a "channel" in the Xtream API response.
     */
    private function getNetworkLiveStreams(Playlist $playlist, string $baseUrl): \Illuminate\Http\JsonResponse
    {
        $networks = $playlist->networks()
            ->where('enabled', true)
            ->orderBy('channel_number')
            ->get();

        // Build a mapping of group name -> stable category ID
        $categoryMap = $this->buildNetworkCategoryMap($networks);

        $liveStreams = [];
        foreach ($networks as $network) {
            $streamIcon = $network->logo ?: $baseUrl.'/placeholder.png';
            $categoryId = $categoryMap[$network->effective_group_name] ?? 'networks';

            $liveStreams[] = [
                'num' => $network->channel_number ?? $network->id,
                'name' => $network->name,
                'stream_type' => 'live',
                'stream_id' => $network->id,
                'stream_icon' => $streamIcon,
                'epg_channel_id' => 'network-'.$network->id,
                'added' => (string) $network->created_at->timestamp,
                'category_id' => $categoryId,
                'category_ids' => [$categoryId],
                'tv_archive' => 0,
                'tv_archive_duration' => 0,
                'custom_sid' => '',
                'thumbnail' => '',
                'direct_source' => '',
            ];
        }

        return response()->json($liveStreams);
    }

    /**
     * Get live categories for a network playlist.
     * Returns distinct categories based on each network's configured group name.
     */
    private function getNetworkLiveCategories(Playlist $playlist): \Illuminate\Http\JsonResponse
    {
        $networks = $playlist->networks()->where('enabled', true)->get();

        if ($networks->isEmpty()) {
            return response()->json([]);
        }

        $categoryMap = $this->buildNetworkCategoryMap($networks);

        $categories = collect($categoryMap)->map(fn (string $id, string $name) => [
            'category_id' => $id,
            'category_name' => $name,
            'parent_id' => 0,
        ])->values()->all();

        return response()->json($categories);
    }

    /**
     * Build a consistent mapping of network group name to category ID.
     *
     * @param  Collection<int, Network>  $networks
     * @return array<string, string>
     */
    private function buildNetworkCategoryMap(Collection $networks): array
    {
        $index = 1;

        return $networks
            ->map(fn (Network $network) => $network->effective_group_name)
            ->unique()
            ->mapWithKeys(fn (string $name) => [$name => 'network-group-'.($index++)])
            ->all();
    }

    /**
     * Get short EPG for a network (from the generated programme schedule).
     */
    private function getNetworkShortEpg(Playlist $playlist, Request $request): \Illuminate\Http\JsonResponse
    {
        $streamId = $request->input('stream_id');
        $limit = (int) ($request->input('limit') ?? 4);

        if (! $streamId) {
            return response()->json(['error' => 'stream_id parameter is required for get_short_epg action'], 400);
        }

        $network = $playlist->networks()
            ->where('enabled', true)
            ->where('id', $streamId)
            ->first();

        if (! $network) {
            return response()->json(['error' => 'Network not found'], 404);
        }

        $now = Carbon::now();
        $programmes = $network->programmes()
            ->where('end_time', '>', $now)
            ->orderBy('start_time')
            ->limit($limit)
            ->with('contentable')
            ->get();

        $epgListings = [];
        foreach ($programmes as $index => $programme) {
            $isCurrentProgramme = $programme->start_time->lte($now) && $programme->end_time->gt($now);

            $epgListings[] = $this->buildNetworkEpgListing($programme, $network, $isCurrentProgramme);
        }

        return response()->json(['epg_listings' => $epgListings]);
    }

    /**
     * Get simple data table EPG for a network (full day schedule).
     */
    private function getNetworkSimpleDataTable(Playlist $playlist, Request $request): \Illuminate\Http\JsonResponse
    {
        $streamId = $request->input('stream_id');

        if (! $streamId) {
            return response()->json(['error' => 'stream_id parameter is required for get_simple_data_table action'], 400);
        }

        $network = $playlist->networks()
            ->where('enabled', true)
            ->where('id', $streamId)
            ->first();

        if (! $network) {
            return response()->json(['error' => 'Network not found'], 404);
        }

        $today = Carbon::now()->startOfDay();
        $tomorrow = $today->copy()->addDay();

        $programmes = $network->programmes()
            ->where('start_time', '>=', $today)
            ->where('start_time', '<', $tomorrow)
            ->orderBy('start_time')
            ->get();

        $now = Carbon::now();
        $epgListings = [];
        foreach ($programmes as $programme) {
            $isCurrentProgramme = $programme->start_time->lte($now) && $programme->end_time->gt($now);

            $epgListings[] = $this->buildNetworkEpgListing($programme, $network, $isCurrentProgramme);
        }

        return response()->json(['epg_listings' => $epgListings]);
    }

    /**
     * Build a single Xtream-API-compatible EPG listing array for a network programme.
     * Resolves description from stored value with fallback to the contentable's metadata.
     *
     * @return array<string, mixed>
     */
    private function buildNetworkEpgListing(NetworkProgramme $programme, Network $network, bool $isCurrentProgramme): array
    {
        $description = $programme->description;

        if (empty($description)) {
            $content = $programme->contentable;
            if ($content) {
                $info = $content->info ?? [];
                $description = $info['plot']
                    ?? $info['description']
                    ?? $content->movie_data['info']['plot']
                    ?? $content->movie_data['info']['description']
                    ?? null;
            }
        }

        return [
            'id' => (string) $programme->id,
            'epg_id' => (string) $network->id,
            'title' => base64_encode($programme->title),
            'lang' => 'en',
            'start' => $programme->start_time->format('Y-m-d H:i:s'),
            'end' => $programme->end_time->format('Y-m-d H:i:s'),
            'description' => base64_encode($description ?? ''),
            'channel_id' => 'network-'.$network->id,
            'start_timestamp' => (string) $programme->start_time->timestamp,
            'stop_timestamp' => (string) $programme->end_time->timestamp,
            'now_playing' => $isCurrentProgramme ? 1 : 0,
            'has_archive' => 0,
        ];
    }

    /**
     * Resolve the PlaylistAuth for the current request's credentials, applying
     * the same enabled/expiry filtering used at authentication time.
     */
    private function resolvePlaylistAuth(string $authMethod, string $username, string $password): ?PlaylistAuth
    {
        if ($authMethod !== 'playlist_auth') {
            return null;
        }

        return PlaylistAuth::where('username', $username)
            ->where('password', $password)
            ->where('enabled', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    /**
     * Resolve the PlaylistViewer from the viewer_id (ulid) ensuring it belongs
     * both to the current playlist context AND to the requesting auth — a
     * playlist_auth login may only resolve its own viewer, and an owner/alias
     * login may only resolve admin-owned (non playlist_auth) viewers.
     */
    private function resolveViewer(string $viewerUlid, $playlist, string $authMethod, string $username, string $password): ?PlaylistViewer
    {
        $viewer = PlaylistViewer::where('ulid', $viewerUlid)
            ->where('viewerable_type', $playlist->getMorphClass())
            ->where('viewerable_id', $playlist->id)
            ->first();

        if (! $viewer) {
            return null;
        }

        if ($authMethod === 'playlist_auth') {
            $playlistAuth = $this->resolvePlaylistAuth($authMethod, $username, $password);

            return ($playlistAuth && $viewer->playlist_auth_id === $playlistAuth->id) ? $viewer : null;
        }

        return $viewer->playlist_auth_id === null ? $viewer : null;
    }

    /**
     * Resolve viewer from request context, with fallback based on auth method:
     * - viewer_id param provided and owned by this auth → use it
     * - viewer_id missing, or provided but owned by someone else (e.g. a
     *   stale client cache predating per-auth viewer isolation) → self-heal
     *   by deriving this auth's own viewer instead of failing the request
     * - playlist_auth → find or create PlaylistViewer linked to the PlaylistAuth
     * - owner_auth / alias_auth → use the admin viewer for this playlist
     */
    private function resolveContextViewer(Request $request, $playlist, string $authMethod, string $username, string $password): ?PlaylistViewer
    {
        if ($viewerUlid = $request->input('viewer_id')) {
            $viewer = $this->resolveViewer($viewerUlid, $playlist, $authMethod, $username, $password);
            if ($viewer) {
                return $viewer;
            }
        }

        return $this->resolveOwnViewer($playlist, $authMethod, $username, $password);
    }

    /**
     * Derive the viewer that belongs to this auth context, independent of any
     * (possibly stale or foreign) viewer_id the client may have sent.
     */
    private function resolveOwnViewer($playlist, string $authMethod, string $username, string $password): ?PlaylistViewer
    {
        if ($authMethod === 'playlist_auth') {
            $playlistAuth = $this->resolvePlaylistAuth($authMethod, $username, $password);

            if ($playlistAuth) {
                $viewer = PlaylistViewer::where('playlist_auth_id', $playlistAuth->id)
                    ->where('viewerable_type', $playlist->getMorphClass())
                    ->where('viewerable_id', $playlist->id)
                    ->first();

                if (! $viewer) {
                    $viewer = PlaylistViewer::create([
                        'ulid' => (string) Str::ulid(),
                        'name' => $playlistAuth->name,
                        'is_admin' => false,
                        'playlist_auth_id' => $playlistAuth->id,
                        'viewerable_type' => $playlist->getMorphClass(),
                        'viewerable_id' => $playlist->id,
                    ]);
                }

                return $viewer;
            }
        }

        // Fall back to admin viewer
        return PlaylistViewer::where('viewerable_type', $playlist->getMorphClass())
            ->where('viewerable_id', $playlist->id)
            ->where('is_admin', true)
            ->first();
    }

    /**
     * Return the viewers visible to the current auth context:
     * - playlist_auth → only that login's own viewer (auto-created if missing)
     * - owner_auth / alias_auth → the admin-owned/switchable viewer profiles
     *   (playlist_auth-owned viewers are a distinct login's identity, not a
     *   selectable "profile", and must never be exposed to other logins)
     */
    private function getViewers(Request $request, $playlist, string $authMethod = 'none', string $username = '', string $password = ''): \Illuminate\Http\JsonResponse
    {
        if ($authMethod === 'playlist_auth') {
            $viewer = $this->resolveContextViewer($request, $playlist, $authMethod, $username, $password);

            return response()->json($viewer ? [$viewer->only(['id', 'ulid', 'name', 'is_admin'])] : []);
        }

        $viewers = PlaylistViewer::where('viewerable_type', $playlist->getMorphClass())
            ->where('viewerable_id', $playlist->id)
            ->whereNull('playlist_auth_id')
            ->orderByDesc('is_admin')
            ->orderBy('name')
            ->get(['id', 'ulid', 'name', 'is_admin']);

        return response()->json($viewers);
    }

    /**
     * Create a new viewer for the current playlist context.
     */
    private function createViewer(Request $request, $playlist): \Illuminate\Http\JsonResponse
    {
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return response()->json(['error' => 'name parameter is required'], 400);
        }

        $viewer = PlaylistViewer::create([
            'ulid' => (string) Str::ulid(),
            'name' => $name,
            'is_admin' => false,
            'viewerable_type' => $playlist->getMorphClass(),
            'viewerable_id' => $playlist->id,
        ]);

        return response()->json([
            'id' => $viewer->id,
            'ulid' => $viewer->ulid,
            'name' => $viewer->name,
            'is_admin' => $viewer->is_admin,
        ]);
    }

    /**
     * Get watch progress for a specific piece of content.
     */
    private function getProgress(Request $request, $playlist, string $authMethod = 'none', string $username = '', string $password = ''): \Illuminate\Http\JsonResponse
    {
        $contentType = $request->input('content_type');
        $streamId = (int) $request->input('stream_id');

        if (! $contentType || ! $streamId) {
            return response()->json(['error' => 'content_type and stream_id are required'], 400);
        }

        $viewer = $this->resolveContextViewer($request, $playlist, $authMethod, $username, $password);
        if (! $viewer) {
            return response()->json(['error' => 'Viewer not found'], 404);
        }

        $progress = ViewerWatchProgress::where('playlist_viewer_id', $viewer->id)
            ->where('content_type', $contentType)
            ->where('stream_id', $streamId)
            ->first();

        return response()->json($progress);
    }

    /**
     * Update (or create) watch progress for a piece of content.
     */
    private function updateProgress(Request $request, $playlist, string $authMethod = 'none', string $username = '', string $password = ''): \Illuminate\Http\JsonResponse
    {
        $contentType = $request->input('content_type');
        $aioItemId = $request->input('aio_item_id');

        // AIOStreams content is keyed by aio_item_id rather than an integer stream_id.
        $isAio = $contentType === 'aiostreams';
        $streamId = $isAio ? null : (int) $request->input('stream_id');

        if (! $contentType || (! $isAio && ! $streamId) || ($isAio && ! $aioItemId)) {
            return response()->json(['error' => 'content_type and (stream_id or aio_item_id) are required'], 400);
        }

        $viewer = $this->resolveContextViewer($request, $playlist, $authMethod, $username, $password);
        if (! $viewer) {
            return response()->json(['error' => 'Viewer not found'], 404);
        }

        $positionSeconds = (int) $request->input('position_seconds', 0);
        $durationSeconds = $request->input('duration_seconds') !== null
            ? (int) $request->input('duration_seconds')
            : null;
        $seriesId = $request->input('series_id') ? (int) $request->input('series_id') : null;
        $seasonNumber = $request->input('season_number') ? (int) $request->input('season_number') : null;
        $episodeNumber = $request->input('episode_number') ? (int) $request->input('episode_number') : null;

        // Auto-mark completed when position reaches 90% of duration.
        // Use $request->boolean() so the string 'false' is treated as false, not truthy.
        $completed = $request->boolean('completed');
        if (! $completed && $durationSeconds && $durationSeconds > 0) {
            $completed = $positionSeconds >= ($durationSeconds * 0.9);
        }

        $data = [
            'last_watched_at' => now(),
        ];

        if ($isAio) {
            $aioIntegrationId = $request->input('aio_integration_id') ? (int) $request->input('aio_integration_id') : null;

            $progress = ViewerWatchProgress::updateOrCreate(
                [
                    'playlist_viewer_id' => $viewer->id,
                    'aio_item_id' => $aioItemId,
                ],
                array_merge($data, [
                    'content_type' => 'aiostreams',
                    'aio_integration_id' => $aioIntegrationId,
                    'title' => $request->input('title'),
                    'episode_title' => $request->input('episode_title'),
                    'thumbnail_url' => $request->input('thumbnail_url'),
                    'backdrop_url' => $request->input('backdrop_url'),
                    'rating' => $request->input('rating'),
                    'year' => $request->input('year'),
                    'plot' => $request->input('plot'),
                    'season_number' => $seasonNumber,
                    'episode_number' => $episodeNumber,
                    'position_seconds' => $positionSeconds,
                    'duration_seconds' => $durationSeconds,
                    'completed' => $completed,
                ])
            );
        } elseif ($contentType === 'live') {
            // For live TV, just increment watch count
            $existing = ViewerWatchProgress::where('playlist_viewer_id', $viewer->id)
                ->where('content_type', 'live')
                ->where('stream_id', $streamId)
                ->first();

            if ($existing) {
                $existing->increment('watch_count');
                $existing->update(['last_watched_at' => now()]);
                $progress = $existing->fresh();
            } else {
                $progress = ViewerWatchProgress::create([
                    'playlist_viewer_id' => $viewer->id,
                    'content_type' => 'live',
                    'stream_id' => $streamId,
                    'watch_count' => 1,
                    'last_watched_at' => now(),
                ]);
            }
        } else {
            $progress = ViewerWatchProgress::updateOrCreate(
                [
                    'playlist_viewer_id' => $viewer->id,
                    'content_type' => $contentType,
                    'stream_id' => $streamId,
                ],
                array_merge($data, [
                    'series_id' => $seriesId,
                    'season_number' => $seasonNumber,
                    'episode_number' => $episodeNumber,
                    'position_seconds' => $positionSeconds,
                    'duration_seconds' => $durationSeconds,
                    'completed' => $completed,
                ])
            );
        }

        return response()->json($progress);
    }

    /**
     * Get all episode progress for a series.
     */
    private function getSeriesProgress(Request $request, $playlist, string $authMethod = 'none', string $username = '', string $password = ''): \Illuminate\Http\JsonResponse
    {
        $seriesId = (int) $request->input('series_id');

        if (! $seriesId) {
            return response()->json(['error' => 'series_id is required'], 400);
        }

        $viewer = $this->resolveContextViewer($request, $playlist, $authMethod, $username, $password);
        if (! $viewer) {
            return response()->json(['error' => 'Viewer not found'], 404);
        }

        $progress = ViewerWatchProgress::where('playlist_viewer_id', $viewer->id)
            ->where('content_type', 'episode')
            ->where('series_id', $seriesId)
            ->orderBy('season_number')
            ->orderBy('episode_number')
            ->orderBy('stream_id')
            ->get(['stream_id', 'season_number', 'episode_number', 'position_seconds', 'duration_seconds', 'completed', 'last_watched_at']);

        return response()->json($progress);
    }

    /**
     * Get recently watched content for a viewer.
     */
    private function getRecentlyWatched(Request $request, $playlist, string $authMethod = 'none', string $username = '', string $password = ''): \Illuminate\Http\JsonResponse
    {
        $viewer = $this->resolveContextViewer($request, $playlist, $authMethod, $username, $password);
        if (! $viewer) {
            return response()->json(['error' => 'Viewer not found'], 404);
        }

        $type = $request->input('type'); // 'live', 'vod', 'episode', or null for all
        $limit = min((int) $request->input('limit', 20), 100);

        $query = ViewerWatchProgress::where('playlist_viewer_id', $viewer->id)
            ->orderByDesc('last_watched_at')
            ->with(['channel', 'episode.series']);

        if ($type && in_array($type, ['live', 'vod', 'episode'])) {
            $query->where('content_type', $type);
        }

        $results = $query->limit($limit)->get();

        $enriched = $results->map(function (ViewerWatchProgress $progress): array {
            $data = $progress->toArray();

            if ($progress->content_type === 'episode') {
                $episode = $progress->episode;
                $series = $episode?->series;
                $episodeInfo = \is_array($episode?->info) ? $episode->info : [];
                $backdrop = null;
                if ($episodeInfo['movie_image'] ?? false) {
                    $backdrop = $episodeInfo['movie_image'];
                }
                if ($episodeInfo['cover_big'] ?? false) {
                    $backdrop = $episodeInfo['cover_big'];
                }
                if (! $backdrop) {
                    $backdropPath = $series?->backdrop_path ?? null;
                    $backdrop = $this->extractFirstUrl($backdropPath);
                }
                if ($backdrop && ($playlist->enable_logo_proxy ?? false)) {
                    $backdrop = $this->proxyImageUrl($backdrop);
                }

                $data['title'] = $series?->name ?? $episode?->title ?? null;
                $data['episode_title'] = $episode?->title ?? null;
                $data['series_name'] = $series?->name ?? null;
                $data['season_number'] = $progress->season_number ?? $episode?->season ?? null;
                $data['episode_number'] = $progress->episode_number ?? $episode?->episode_num ?? null;
                $data['thumbnail_url'] = $episode?->cover ?? $series?->cover ?? null;
                $data['backdrop_url'] = $backdrop;
                $data['rating'] = isset($episodeInfo['rating']) ? (string) $episodeInfo['rating'] : null;
                $data['runtime'] = $episodeInfo['duration'] ?? null;
            } elseif ($progress->content_type === 'vod') {
                $channel = $progress->channel;
                $info = \is_array($channel?->info) ? $channel->info : [];
                $backdropPaths = $info['backdrop_path'] ?? [];
                if (is_string($backdropPaths)) {
                    $backdropPaths = json_decode($backdropPaths, true) ?? [];
                }
                $backdropPaths = array_filter($backdropPaths);
                if ($playlist->enable_logo_proxy ?? false) {
                    $backdropPaths = array_map(fn ($path) => $this->proxyImageUrl($path), $backdropPaths);
                }

                $data['title'] = $channel?->title ?? $channel?->name ?? null;
                $data['episode_title'] = null;
                $data['series_name'] = null;
                $data['season_number'] = null;
                $data['episode_number'] = null;
                $data['thumbnail_url'] = $channel?->logo ?? $channel?->logo_internal ?? null;
                $data['backdrop_url'] = $this->extractFirstUrl($backdropPaths);
                $data['rating'] = $channel?->rating ?? null;
                $data['runtime'] = $info['duration'] ?? null;
                $data['plot'] = $info['plot'] ?? $info['description'] ?? $info['desc'] ?? null;
                $data['genre'] = $info['genre'] ?? $info['category_name'] ?? null;
                $data['year'] = $channel?->year ?? $info['releasedate'] ?? $info['year'] ?? null;
            } elseif ($progress->content_type === 'aiostreams') {
                // AIOStreams — metadata is stored denormalised; no join needed.
                $data['title'] = $progress->title;
                $data['episode_title'] = $progress->episode_title;
                $data['series_name'] = null;
                $data['season_number'] = $progress->season_number;
                $data['episode_number'] = $progress->episode_number;
                $data['thumbnail_url'] = $progress->thumbnail_url;
                $data['backdrop_url'] = $progress->backdrop_url;
                $data['rating'] = $progress->rating;
                $data['runtime'] = $this->formatRuntimeFromSeconds($progress->duration_seconds);
                $data['plot'] = $progress->plot;
                $data['year'] = $progress->year;
                $data['aio_item_id'] = $progress->aio_item_id;
                $data['aio_integration_id'] = $progress->aio_integration_id;
            } else {
                // live
                $channel = $progress->channel;

                $data['title'] = $channel?->title ?? $channel?->name ?? null;
                $data['episode_title'] = null;
                $data['series_name'] = null;
                $data['season_number'] = null;
                $data['episode_number'] = null;
                $data['thumbnail_url'] = $channel?->logo ?? $channel?->logo_internal ?? null;
                $data['backdrop_url'] = null;
                $data['rating'] = null;
                $data['runtime'] = null;
            }

            unset($data['channel'], $data['episode']);

            return $data;
        });

        return response()->json($enriched);
    }

    /**
     * Get all favorites for a viewer, optionally filtered by content_type.
     */
    private function getFavorites(Request $request, $playlist, string $authMethod = 'none', string $username = '', string $password = ''): \Illuminate\Http\JsonResponse
    {
        $viewer = $this->resolveContextViewer($request, $playlist, $authMethod, $username, $password);
        if (! $viewer) {
            return response()->json(['error' => 'Viewer not found'], 404);
        }

        $type = $request->input('content_type'); // 'live', 'vod', 'series', 'aiostreams', or null for all
        $imdbId = $request->input('imdb_id'); // cross-reference: favorites of this title from any content_type/source

        $query = ViewerFavorite::where('playlist_viewer_id', $viewer->id);
        if ($type && in_array($type, ['live', 'vod', 'series', 'aiostreams'], true)) {
            $query->where('content_type', $type);
        }
        if ($imdbId) {
            $query->forImdbId($imdbId);
        }

        $favorites = $query->get(self::FAVORITE_COLUMNS);

        return response()->json($favorites);
    }

    /**
     * Add or remove a single favorite for a viewer. Idempotent: favoriting an
     * already-favorited item (or unfavoriting one that isn't) is a no-op.
     */
    private function toggleFavorite(Request $request, $playlist, string $authMethod = 'none', string $username = '', string $password = ''): \Illuminate\Http\JsonResponse
    {
        $contentType = $request->input('content_type');
        $aioItemId = $request->input('aio_item_id');
        $isAio = $contentType === 'aiostreams';
        $streamId = $isAio ? null : (int) $request->input('stream_id');
        $favorited = $request->boolean('favorited', true);

        if (! in_array($contentType, ['live', 'vod', 'series', 'aiostreams'], true)) {
            return response()->json(['error' => 'content_type must be one of live, vod, series, aiostreams'], 400);
        }

        if ((! $isAio && ! $streamId) || ($isAio && ! $aioItemId)) {
            return response()->json(['error' => 'stream_id or aio_item_id is required'], 400);
        }

        $viewer = $this->resolveContextViewer($request, $playlist, $authMethod, $username, $password);
        if (! $viewer) {
            return response()->json(['error' => 'Viewer not found'], 404);
        }

        $key = $isAio
            ? ['playlist_viewer_id' => $viewer->id, 'aio_item_id' => $aioItemId]
            : ['playlist_viewer_id' => $viewer->id, 'content_type' => $contentType, 'stream_id' => $streamId];

        $aioMetadata = [];

        if ($favorited) {
            $crossRef = $this->resolveFavoriteCrossReference(
                $contentType,
                $streamId,
                $aioItemId,
                $request->input('imdb_id'),
                $request->input('tmdb_id'),
            );

            $aioMetadata = $isAio ? $this->aioMetadataFromRequest($request) : [];

            $attributes = array_merge($key, [
                'content_type' => $contentType,
                'favorited_at' => now(),
                'imdb_id' => $crossRef['imdb'],
                'tmdb_id' => $crossRef['tmdb'],
            ], $aioMetadata);

            ViewerFavorite::updateOrCreate($key, $attributes);
        } else {
            ViewerFavorite::where($key)->delete();
        }

        broadcast(ViewerFavoriteEvent::build(
            $playlist,
            $viewer,
            $contentType,
            $streamId,
            $aioItemId,
            $favorited,
            $aioMetadata,
        ));

        return response()->json(['favorited' => $favorited]);
    }

    /**
     * @return array{aio_integration_id: ?int, title: ?string, thumbnail_url: ?string, item_type: ?string}
     */
    private function aioMetadataFromRequest(Request $request): array
    {
        return [
            'aio_integration_id' => $request->input('aio_integration_id') ? (int) $request->input('aio_integration_id') : null,
            'title' => $request->input('title'),
            'thumbnail_url' => $request->input('thumbnail_url'),
            'item_type' => $request->input('item_type'),
        ];
    }

    /**
     * Server-side lookup of universal cross-reference ids for a favorite.
     * vod/series are resolved from the local Channel/Series row — authoritative,
     * never trusted from the client. AIOStreams items have no local row, so their
     * imdb_id is derived from the addon's own item id when it looks like an IMDb
     * id (the common case for AIOStreams catalogs), falling back to whatever the
     * addon's metadata told the client, since the server can't independently
     * verify third-party addon content.
     *
     * @return array{imdb: ?string, tmdb: ?string}
     */
    private function resolveFavoriteCrossReference(
        string $contentType,
        ?int $streamId,
        ?string $aioItemId,
        ?string $clientImdbId,
        ?string $clientTmdbId,
    ): array {
        if ($contentType === 'vod' && $streamId) {
            $channel = Channel::find($streamId);
            $tmdbId = $channel?->getTmdbId();

            return [
                'imdb' => $channel?->getImdbId(),
                'tmdb' => $tmdbId !== null ? (string) $tmdbId : null,
            ];
        }

        if ($contentType === 'series' && $streamId) {
            $ids = Series::find($streamId)?->getMovieDbIds() ?? [];

            return [
                'imdb' => $ids['imdb'] ?? null,
                'tmdb' => isset($ids['tmdb']) ? (string) $ids['tmdb'] : null,
            ];
        }

        if ($contentType === 'aiostreams') {
            $looksLikeImdbId = is_string($aioItemId) && preg_match('/^tt\d+$/', $aioItemId) === 1;

            return [
                'imdb' => $clientImdbId ?: ($looksLikeImdbId ? $aioItemId : null),
                'tmdb' => $clientTmdbId,
            ];
        }

        return ['imdb' => null, 'tmdb' => null];
    }

    /**
     * One-time reconciliation for a client with pre-existing local-only favorites:
     * union the client's set into the viewer's server-side favorites and return the
     * merged result. Clients call this once (e.g. on first connect after upgrading to
     * server-backed favorites) and treat get_favorites/toggle_favorite as authoritative
     * afterward — this endpoint does not delete anything, only adds.
     */
    private function syncFavorites(Request $request, $playlist, string $authMethod = 'none', string $username = '', string $password = ''): \Illuminate\Http\JsonResponse
    {
        $viewer = $this->resolveContextViewer($request, $playlist, $authMethod, $username, $password);
        if (! $viewer) {
            return response()->json(['error' => 'Viewer not found'], 404);
        }

        $items = $request->input('favorites', []);
        if (! is_array($items)) {
            return response()->json(['error' => 'favorites must be an array'], 400);
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $contentType = $item['content_type'] ?? null;
            if (! in_array($contentType, ['live', 'vod', 'series', 'aiostreams'], true)) {
                continue;
            }

            $isAio = $contentType === 'aiostreams';
            $aioItemId = $item['aio_item_id'] ?? null;
            $streamId = $isAio ? null : (isset($item['stream_id']) ? (int) $item['stream_id'] : null);

            if ((! $isAio && ! $streamId) || ($isAio && ! $aioItemId)) {
                continue;
            }

            $key = $isAio
                ? ['playlist_viewer_id' => $viewer->id, 'aio_item_id' => $aioItemId]
                : ['playlist_viewer_id' => $viewer->id, 'content_type' => $contentType, 'stream_id' => $streamId];

            $crossRef = $this->resolveFavoriteCrossReference(
                $contentType,
                $streamId,
                $aioItemId,
                is_string($item['imdb_id'] ?? null) ? $item['imdb_id'] : null,
                is_string($item['tmdb_id'] ?? null) ? $item['tmdb_id'] : null,
            );

            $attributes = array_merge($key, [
                'content_type' => $contentType,
                'favorited_at' => now(),
                'imdb_id' => $crossRef['imdb'],
                'tmdb_id' => $crossRef['tmdb'],
            ], $isAio ? [
                'aio_integration_id' => isset($item['aio_integration_id']) ? (int) $item['aio_integration_id'] : null,
                'title' => is_string($item['title'] ?? null) ? $item['title'] : null,
                'thumbnail_url' => is_string($item['thumbnail_url'] ?? null) ? $item['thumbnail_url'] : null,
                'item_type' => is_string($item['item_type'] ?? null) ? $item['item_type'] : null,
            ] : []);

            ViewerFavorite::updateOrCreate($key, $attributes);
        }

        $favorites = ViewerFavorite::where('playlist_viewer_id', $viewer->id)
            ->get(self::FAVORITE_COLUMNS);

        return response()->json($favorites);
    }

    /**
     * Wrap an image URL in the logo proxy, unless it's already an app-hosted URL
     * (e.g. a media server image proxied at sync time), in which case it's returned
     * untouched to avoid double-proxying it through the logo proxy's own fetch.
     */
    private function proxyImageUrl(?string $url): ?string
    {
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL) || str_starts_with($url, url('/'))) {
            return $url;
        }

        return LogoProxyController::generateProxyUrl($url);
    }

    /**
     * Extract the first URL from a backdrop_path value.
     * Handles both native arrays and double-encoded JSON strings.
     */
    private function extractFirstUrl(mixed $value): ?string
    {
        if (\is_array($value)) {
            return $value[0] ?? null;
        }
        if (\is_string($value) && ! empty($value)) {
            $decoded = json_decode($value, true);
            if (\is_array($decoded)) {
                return $decoded[0] ?? null;
            }

            return $value;
        }

        return null;
    }

    /**
     * Resolve optional m3u-editor capabilities advertised to compatible clients.
     *
     * DVR is only advertised when the effective playlist has DVR enabled and,
     * for PlaylistAuth credentials, the individual auth is allowed to use DVR.
     * Owner/alias credentials keep access when the playlist itself has DVR enabled.
     *
     * @return array<int, string>
     */
    private function resolveM3uEditorFeatures($playlist, string $authMethod, ?PlaylistAuth $playlistAuth): array
    {
        $features = ['viewers', 'progress'];

        if ($this->canAdvertiseDvrFeature($playlist, $authMethod, $playlistAuth)) {
            $features[] = 'dvr';
        }

        if ($this->requestsFeatureEnabled($playlist, $authMethod, $playlistAuth)) {
            $features[] = 'requests';
        }

        if ($this->hasAIOStreams($playlist, $authMethod, $playlistAuth)) {
            $features[] = 'aiostreams';
        }

        if ($this->canAdvertiseProxyFeature($playlist, $authMethod, $playlistAuth)) {
            $features[] = 'proxy';
        }

        return $features;
    }

    private function canAdvertiseLibraryPublishing(
        mixed $playlist,
        string $authMethod,
        ?PlaylistAuth $playlistAuth,
    ): bool {
        $effectivePlaylist = $this->resolveEffectivePlaylist($playlist);

        return $effectivePlaylist !== null
            && $this->libraryPublishingAuthorized($effectivePlaylist, $authMethod, $playlistAuth);
    }

    /**
     * Core authorization check for the managed-library-publishing protocol,
     * shared by canAdvertiseLibraryPublishing() (raw $playlist, used for the
     * info-response feature flag) and the three action handlers below (which
     * already hold an $effectivePlaylist and must not re-resolve it).
     */
    private function libraryPublishingAuthorized(
        Playlist|CustomPlaylist|MergedPlaylist $effectivePlaylist,
        string $authMethod,
        ?PlaylistAuth $playlistAuth,
    ): bool {
        if (! $effectivePlaylist->user?->canUseIntegrations()) {
            return false;
        }

        if (! in_array($authMethod, ['owner_auth', 'playlist_auth'], true)) {
            return false;
        }

        if ($authMethod === 'playlist_auth' && ! $playlistAuth?->library_publishing_enabled) {
            return false;
        }

        return MediaServerIntegration::query()
            ->where('user_id', $effectivePlaylist->user_id)
            ->where('type', 'emby')
            ->where('enabled', true)
            ->exists();
    }

    /**
     * Build the standard 400/422 error response for a failed api_version-
     * gated validator, shared by the three managed-library-publishing action
     * handlers below.
     */
    private function apiVersionValidationError(Request $request): JsonResponse
    {
        if ($request->integer('api_version') !== 1) {
            return $this->requestError(
                'unsupported_api_version',
                'The requested API version is not supported.',
                400,
            );
        }

        return $this->requestError('invalid_request', 'The request parameters are invalid.', 422);
    }

    /**
     * The 403 returned by every managed-library-publishing action handler when
     * the credentials aren't authorized for the protocol.
     */
    private function libraryPublishingUnavailableError(): JsonResponse
    {
        return $this->requestError(
            'library_publishing_unavailable',
            'Managed library publishing is not available for these credentials.',
            403,
        );
    }

    private function registerManagedLibraryPublisher(
        Request $request,
        mixed $playlist,
        string $authMethod,
        ?PlaylistAuth $playlistAuth,
    ): JsonResponse {
        $effectivePlaylist = $this->resolveEffectivePlaylist($playlist);
        if (! $effectivePlaylist || ! $this->libraryPublishingAuthorized($effectivePlaylist, $authMethod, $playlistAuth)) {
            return $this->libraryPublishingUnavailableError();
        }

        $input = $request->all();
        if (is_array($input['writable_paths'] ?? null)) {
            $input['writable_paths'] = array_map(
                fn (mixed $path): mixed => is_string($path) ? trim($path) : $path,
                $input['writable_paths'],
            );
        }

        $validator = Validator::make($input, [
            'api_version' => ['required', 'integer', 'in:1'],
            'integration_id' => ['required', 'integer', 'min:1'],
            'writable_paths' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'writable_paths.*' => [
                'required',
                'string',
                'distinct:strict',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! MediaServerIntegration::isSafeWritablePath($value)) {
                        $fail('The :attribute must be a valid absolute path.');
                    }
                },
            ],
        ]);
        if ($validator->fails()) {
            return $this->apiVersionValidationError($request);
        }

        $validated = $validator->validated();
        $integration = MediaServerIntegration::query()
            ->whereKey($validated['integration_id'])
            ->where('user_id', $effectivePlaylist->user_id)
            ->where('type', 'emby')
            ->where('enabled', true)
            ->first();
        if (! $integration) {
            return $this->requestError('integration_not_found', 'The Emby integration was not found.', 404);
        }

        $integration->updateQuietly([
            'emby_publisher_writable_paths' => $validated['writable_paths'],
            'emby_publisher_capabilities_updated_at' => now(),
        ]);

        return $this->requestSuccess([
            'integration_id' => $integration->id,
            'writable_paths' => $integration->getEmbyPublisherWritablePaths(),
        ]);
    }

    private function managedLibraryCatalog(
        Request $request,
        mixed $playlist,
        string $authMethod,
        ?PlaylistAuth $playlistAuth,
    ): JsonResponse {
        $effectivePlaylist = $this->resolveEffectivePlaylist($playlist);
        if (! $effectivePlaylist || ! $this->libraryPublishingAuthorized($effectivePlaylist, $authMethod, $playlistAuth)) {
            return $this->libraryPublishingUnavailableError();
        }

        $validator = Validator::make($request->all(), [
            'api_version' => ['required', 'integer', 'in:1'],
        ]);
        if ($validator->fails()) {
            return $this->apiVersionValidationError($request);
        }

        return response()->json(app(EmbyPublicationCatalogService::class)->buildForUser($effectivePlaylist->user));
    }

    private function managedLibrarySyncResult(
        Request $request,
        mixed $playlist,
        string $authMethod,
        ?PlaylistAuth $playlistAuth,
    ): JsonResponse {
        $effectivePlaylist = $this->resolveEffectivePlaylist($playlist);
        if (! $effectivePlaylist || ! $this->libraryPublishingAuthorized($effectivePlaylist, $authMethod, $playlistAuth)) {
            return $this->libraryPublishingUnavailableError();
        }

        $validator = Validator::make($request->all(), [
            'api_version' => ['required', 'integer', 'in:1'],
            'integration_id' => ['required', 'integer', 'min:1'],
            'mapping_uuid' => ['required', 'uuid'],
            'revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'status' => ['required', 'string', 'in:success,failed'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'error' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($validator->fails()) {
            return $this->apiVersionValidationError($request);
        }

        $validated = $validator->validated();
        $result = DB::transaction(function () use ($effectivePlaylist, $validated): array {
            $mapping = EmbyLibraryMapping::query()
                ->with('integration')
                ->where('user_id', $effectivePlaylist->user_id)
                ->where('media_server_integration_id', $validated['integration_id'])
                ->where('uuid', $validated['mapping_uuid'])
                ->where('enabled', true)
                ->lockForUpdate()
                ->first();

            if (! $mapping) {
                return ['error' => 'mapping_not_found'];
            }

            if (! hash_equals((string) $mapping->last_planned_revision, $validated['revision'])) {
                return ['error' => 'stale_revision'];
            }

            if ($validated['status'] !== 'success') {
                $mapping->updateQuietly([
                    'status' => 'failed',
                    'status_summary' => EmbyLibraryMapping::redactSummary($validated['summary'] ?? null),
                    'error_summary' => EmbyLibraryMapping::redactSummary($validated['error'] ?? null),
                ]);

                return ['error' => 'sync_failed'];
            }

            if ($mapping->last_applied_revision === $validated['revision']) {
                return [
                    'mapping' => $mapping,
                    'duplicate' => true,
                    'refresh' => false,
                ];
            }

            $mapping->updateQuietly([
                'last_applied_revision' => $validated['revision'],
                'last_success_at' => now(),
                'status' => 'synced',
                'status_summary' => EmbyLibraryMapping::redactSummary($validated['summary'] ?? null)
                    ?? 'Revision applied.',
                'error_summary' => null,
            ]);

            return [
                'mapping' => $mapping,
                'duplicate' => false,
                'refresh' => (bool) ($mapping->options['refresh'] ?? true),
            ];
        });

        if (isset($result['error'])) {
            return match ($result['error']) {
                'mapping_not_found' => $this->requestError('mapping_not_found', 'The mapping was not found.', 404),
                'stale_revision' => $this->requestError('stale_revision', 'The reported revision is not current.', 409),
                default => $this->requestError('sync_failed', 'The companion reported a failed sync.', 422),
            };
        }

        if ($result['refresh']) {
            Bus::dispatch(new RefreshMediaServerLibraryJob(
                $result['mapping']->integration,
                notify: false,
            ));
        }

        return $this->requestSuccess([
            'applied' => true,
            'duplicate' => $result['duplicate'],
            'mapping_uuid' => $result['mapping']->uuid,
            'revision' => $result['mapping']->last_applied_revision,
        ]);
    }

    /**
     * Resolve `tv_archive_duration` (days) for a live stream entry (#1389).
     *
     * `Channel::$shift` is stored in hours (see M3uImportCatchupShiftTest),
     * matching the various hour/day provider attributes it's normalized
     * from, but standard Xtream clients (and m3u-tv) expect this field in
     * days - so it must be converted here, not echoed as-is.
     *
     * Always returns a plain int, never null: many third-party Xtream
     * clients (e.g. TiviMate, explicitly supported elsewhere in this
     * controller) deserialize this field as a non-nullable integer, so a
     * JSON null risks breaking their entire live-stream list rather than
     * degrading gracefully. When catchup is enabled but no duration is
     * known, `dev.default_epg_catchup_days` (env `DEFAULT_EPG_CATCHUP_DAYS`)
     * is reported instead of 0 - 0 would assert "zero retention" to a
     * client, when the retention is actually just unconfigured. Users who'd
     * rather advertise no retention in that case can set the env var to 0.
     */
    private function resolveTvArchiveDuration(Channel $channel, bool $disableCatchup): int
    {
        if ($disableCatchup) {
            return 0;
        }

        if (! $channel->catchup && ! $channel->shift) {
            return 0;
        }

        if ($channel->shift) {
            return (int) ceil($channel->shift / 24);
        }

        return (int) config('dev.default_epg_catchup_days', 7);
    }

    private function hasAIOStreams($playlist, string $authMethod, ?PlaylistAuth $playlistAuth): bool
    {
        return app(AIOStreamsAuthorizationService::class)->isEnabled($playlist, $authMethod, $playlistAuth);
    }

    /**
     * Unwrap a PlaylistAlias to its effective playlist; otherwise return the
     * playlist as-is if it's one of the three playlist types that carry their
     * own DVR/Requests/AIOStreams settings.
     */
    private function resolveEffectivePlaylist($playlist): Playlist|CustomPlaylist|MergedPlaylist|null
    {
        $effective = $playlist instanceof PlaylistAlias
            ? $playlist->getEffectivePlaylist()
            : $playlist;

        return $effective instanceof Playlist || $effective instanceof CustomPlaylist || $effective instanceof MergedPlaylist
            ? $effective
            : null;
    }

    /**
     * Build the AIOStreams payload for the auth response.
     * Returns an array of integration configs with their catalog lists.
     *
     * @return array<int, array{id: int, name: string, catalogs: array<int, array{id: string, type: string, name: string}>}>
     */
    private function resolveAIOStreamsData($playlist, array $features): array
    {
        if (! in_array('aiostreams', $features)) {
            return [];
        }

        $effectivePlaylist = $this->resolveEffectivePlaylist($playlist);

        if (! $effectivePlaylist) {
            return [];
        }

        $integration = $effectivePlaylist->aiostreamsIntegration;
        if (! $integration || ! $integration->enabled) {
            return [];
        }

        return [
            [
                'id' => $integration->id,
                'name' => $integration->name,
                'logo' => $integration->aiostreams_logo,
                'catalogs' => $this->filterAIOStreamsCatalogs($integration),
            ],
        ];
    }

    /**
     * Filter AIOStreams catalogs by the integration's selected catalog IDs.
     * A null selection means all catalogs are enabled.
     *
     * @return array<int, array{id: string, type: string, name: string, searchable: bool}>
     */
    private function filterAIOStreamsCatalogs($integration): array
    {
        $all = $integration->aiostreams_catalogs ?? [];

        if ($integration->aiostreams_enable_all_catalogs) {
            return $all;
        }

        $selectedSet = array_flip($integration->aiostreams_selected_catalog_ids ?? []);

        return collect($all)
            ->filter(fn (array $catalog) => isset($selectedSet[$catalog['id'].'_'.$catalog['type']]))
            ->values()
            ->all();
    }

    private function canAdvertiseDvrFeature($playlist, string $authMethod, ?PlaylistAuth $playlistAuth): bool
    {
        return $this->dvrCapabilityGranted($this->resolveDvrPlaylist($playlist), $authMethod, $playlistAuth);
    }

    /**
     * Single source of truth for whether DVR is usable at all: global config,
     * the effective playlist's DvrSetting, and (for guest credentials) the
     * PlaylistAuth's own dvr_enabled flag. Both feature advertisement and every
     * direct DVR action must agree with this result.
     */
    private function dvrCapabilityGranted(
        Playlist|CustomPlaylist|MergedPlaylist|null $dvrPlaylist,
        string $authMethod,
        ?PlaylistAuth $playlistAuth
    ): bool {
        return DvrCapabilityGate::granted(
            $dvrPlaylist?->dvrSetting,
            $playlistAuth,
            $authMethod === 'playlist_auth'
        );
    }

    private function resolveDvrPlaylist($playlist): Playlist|CustomPlaylist|MergedPlaylist|null
    {
        return $this->resolveEffectivePlaylist($playlist);
    }

    /**
     * Get short EPG for an attached network on custom/merged playlists.
     * Stream ID format: network-{id}
     */
    private function getAttachedNetworkShortEpg(Model $playlist, string $streamId, int $limit = 4): \Illuminate\Http\JsonResponse
    {
        // Extract network ID from stream_id (format: network-{id})
        $networkId = (int) str_replace('network-', '', $streamId);

        // Check if playlist supports attached networks
        if (! method_exists($playlist, 'enabled_networks') || ! $playlist->include_networks_in_m3u) {
            return response()->json(['epg_listings' => []]);
        }

        $network = $playlist->enabled_networks()
            ->where('networks.id', $networkId)
            ->first();

        if (! $network) {
            return response()->json(['epg_listings' => []]);
        }

        $now = Carbon::now();
        $programmes = $network->programmes()
            ->where('end_time', '>', $now)
            ->orderBy('start_time')
            ->limit($limit)
            ->with('contentable')
            ->get();

        $epgListings = [];
        foreach ($programmes as $programme) {
            $isCurrentProgramme = $programme->start_time->lte($now) && $programme->end_time->gt($now);
            $epgListings[] = $this->buildNetworkEpgListing($programme, $network, $isCurrentProgramme);
        }

        return response()->json(['epg_listings' => $epgListings]);
    }

    /**
     * Get simple data table EPG for an attached network on custom/merged playlists.
     * Stream ID format: network-{id}
     */
    private function getAttachedNetworkSimpleDataTable(Model $playlist, string $streamId): \Illuminate\Http\JsonResponse
    {
        // Extract network ID from stream_id (format: network-{id})
        $networkId = (int) str_replace('network-', '', $streamId);

        // Check if playlist supports attached networks
        if (! method_exists($playlist, 'enabled_networks') || ! $playlist->include_networks_in_m3u) {
            return response()->json(['epg_listings' => []]);
        }

        $network = $playlist->enabled_networks()
            ->where('networks.id', $networkId)
            ->first();

        if (! $network) {
            return response()->json(['epg_listings' => []]);
        }

        $today = Carbon::now()->startOfDay();
        $tomorrow = $today->copy()->addDay();

        $programmes = $network->programmes()
            ->where('start_time', '>=', $today)
            ->where('start_time', '<', $tomorrow)
            ->orderBy('start_time')
            ->with('contentable')
            ->get();

        $now = Carbon::now();
        $epgListings = [];
        foreach ($programmes as $programme) {
            $isCurrentProgramme = $programme->start_time->lte($now) && $programme->end_time->gt($now);
            $epgListings[] = $this->buildNetworkEpgListing($programme, $network, $isCurrentProgramme);
        }

        return response()->json(['epg_listings' => $epgListings]);
    }

    /**
     * List DVR recordings for the authenticated playlist, optionally filtered by status.
     */
    private function getDvrRecordings(Request $request, $playlist, string $username, string $password, ?PlaylistAuth $playlistAuth): \Illuminate\Http\JsonResponse
    {
        $dvrSetting = $playlist->dvrSetting;

        if (! $dvrSetting) {
            return response()->json([]);
        }

        $status = $request->input('status');
        $limit = min((int) $request->input('limit', 50), 200);
        $offset = (int) $request->input('offset', 0);

        $query = DvrRecording::where('dvr_setting_id', $dvrSetting->id)
            ->when($playlistAuth, fn ($q) => $q->where('playlist_auth_id', $playlistAuth->id))
            ->with('channel')
            ->orderByDesc('scheduled_start');

        if ($status) {
            $statusEnum = DvrRecordingStatus::tryFrom($status);
            if ($statusEnum) {
                $query->where('status', $statusEnum);
            }
        }

        $recordings = $query->skip($offset)->take($limit)->get();

        return response()->json($recordings->map(fn (DvrRecording $r) => $this->formatDvrRecording($r, $username, $password)));
    }

    /**
     * Get a single DVR recording by UUID.
     */
    private function getDvrRecording(Request $request, $playlist, string $username, string $password, ?PlaylistAuth $playlistAuth): \Illuminate\Http\JsonResponse
    {
        $uuid = $request->input('recording_id');

        if (! $uuid) {
            return response()->json(['error' => 'recording_id parameter is required'], 400);
        }

        $dvrSetting = $playlist->dvrSetting;

        if (! $dvrSetting) {
            return response()->json(['error' => 'DVR not configured for this playlist'], 404);
        }

        $recording = DvrRecording::where('dvr_setting_id', $dvrSetting->id)
            ->where('uuid', $uuid)
            ->when($playlistAuth, fn ($q) => $q->where('playlist_auth_id', $playlistAuth->id))
            ->with('channel')
            ->first();

        if (! $recording) {
            return response()->json(['error' => 'Recording not found'], 404);
        }

        return response()->json($this->formatDvrRecording($recording, $username, $password, true));
    }

    /**
     * Get the current user/guest's DVR storage usage against their quota.
     *
     * A guest (PlaylistAuth) only ever sees their own usage, scoped to their own
     * recordings, regardless of whether they have an explicit quota configured —
     * guests never see account-wide totals. The account owner sees the whole
     * DVR setting's usage against its global quota.
     */
    private function getDvrStorage($playlist, ?PlaylistAuth $playlistAuth): \Illuminate\Http\JsonResponse
    {
        if ($playlistAuth) {
            $usedBytes = $playlistAuth->storage_used_bytes;
            $quotaBytes = $playlistAuth->dvr_storage_quota_gb !== null
                ? $playlistAuth->dvr_storage_quota_gb * 1024 ** 3
                : null;

            return response()->json([
                'used_bytes' => $usedBytes,
                'quota_bytes' => $quotaBytes,
                'percent_used' => $quotaBytes !== null
                    ? ($quotaBytes > 0 ? min(100, round($usedBytes / $quotaBytes * 100, 1)) : 100)
                    : null,
                'recording_count' => $playlistAuth->dvrRecordings()->count(),
                'scope' => 'guest',
            ]);
        }

        $dvrSetting = $playlist->dvrSetting;

        if (! $dvrSetting) {
            return response()->json([
                'used_bytes' => 0,
                'quota_bytes' => null,
                'percent_used' => null,
                'recording_count' => 0,
                'scope' => 'account',
            ]);
        }

        $usedBytes = $dvrSetting->storage_used_bytes;
        $quotaBytes = $dvrSetting->global_disk_quota_gb > 0
            ? $dvrSetting->global_disk_quota_gb * 1024 ** 3
            : null;

        return response()->json([
            'used_bytes' => $usedBytes,
            'quota_bytes' => $quotaBytes,
            'percent_used' => $quotaBytes ? min(100, round($usedBytes / $quotaBytes * 100, 1)) : null,
            'recording_count' => $dvrSetting->recordings()->count(),
            'scope' => 'account',
        ]);
    }

    /**
     * List all enabled series recording rules for the authenticated playlist.
     */
    private function listDvrSeriesRules(Request $request, $playlist, ?PlaylistAuth $playlistAuth): \Illuminate\Http\JsonResponse
    {
        $dvrSetting = $playlist->dvrSetting;

        if (! $dvrSetting) {
            return response()->json([]);
        }

        $rules = DvrRecordingRule::where('dvr_setting_id', $dvrSetting->id)
            ->where('type', DvrRuleType::Series)
            ->where('enabled', true)
            ->when($playlistAuth, fn ($q) => $q->where('playlist_auth_id', $playlistAuth->id))
            ->with('channel')
            ->withCount('recordings')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($rules->map(fn (DvrRecordingRule $rule) => $this->formatDvrSeriesRule($rule)));
    }

    /**
     * Delete a series recording rule by ID.
     */
    private function deleteDvrSeriesRule(Request $request, $playlist, ?PlaylistAuth $playlistAuth): \Illuminate\Http\JsonResponse
    {
        $ruleId = (int) $request->input('rule_id');

        if (! $ruleId) {
            return response()->json(['error' => 'rule_id parameter is required'], 400);
        }

        $dvrSetting = $playlist->dvrSetting;

        if (! $dvrSetting) {
            return response()->json(['error' => 'DVR not configured for this playlist'], 404);
        }

        $rule = DvrRecordingRule::where('dvr_setting_id', $dvrSetting->id)
            ->where('id', $ruleId)
            ->where('type', DvrRuleType::Series)
            ->when($playlistAuth, fn ($q) => $q->where('playlist_auth_id', $playlistAuth->id))
            ->first();

        if (! $rule) {
            return response()->json(['error' => 'Series rule not found'], 404);
        }

        $rule->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Search EPG programme listings across all mapped channels to discover shows
     * available for series recording. Uses SeriesKey::normalize() on programme
     * titles so the returned groups match DvrRecordingRule::saving hook's own
     * normalization — this guarantees that a series rule created from search
     * results will match future EPG programme data for the same show.
     */
    private function searchEpgShows(Request $request, $playlist, ?PlaylistAuth $playlistAuth): \Illuminate\Http\JsonResponse
    {
        $q = trim((string) $request->input('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['error' => 'q parameter is required (minimum 2 characters)'], 400);
        }

        $dvrSetting = $playlist->dvrSetting;

        if (! $dvrSetting?->enabled) {
            return response()->json(['error' => 'DVR is not enabled for this playlist'], 422);
        }

        // Pre-fetch the set of existing Series rules for this DVR setting (scoped by
        // playlist_auth when applicable) keyed by their auto-derived normalized_title,
        // so each result can advertise `has_series_rule` + `series_rule_id` without
        // a per-group query. Uses the same SeriesKey::normalize normalization as the
        // grouping below — which matches DvrRecordingRule::saving's column population —
        // so a normalized-title lookup here is consistent with what a future rule
        // created from this result would store.
        $seriesRuleTitles = DvrRecordingRule::where('dvr_setting_id', $dvrSetting->id)
            ->where('type', DvrRuleType::Series)
            ->when($playlistAuth, fn ($q) => $q->where('playlist_auth_id', $playlistAuth->id))
            ->pluck('id', 'normalized_title');

        // Collect the set of EPG channel_id strings this playlist's channels are mapped to.
        $playlistChannels = $playlist->channels()
            ->whereNotNull('epg_channel_id')
            ->with('epgChannel')
            ->get();

        $epgChannelIds = $playlistChannels
            ->map(fn (Channel $ch) => $ch->epgChannel?->channel_id)
            ->filter()
            ->unique()
            ->values();

        if ($epgChannelIds->isEmpty()) {
            return response()->json([]);
        }

        // Build lookup: epg_channel_id string → [channel_id, channel_name] from the playlist.
        $channelLookup = [];
        foreach ($playlistChannels as $ch) {
            $epgChanId = $ch->epgChannel?->channel_id;
            if ($epgChanId && ! isset($channelLookup[$epgChanId])) {
                $channelLookup[$epgChanId] = [
                    'channel_id' => $ch->id,
                    'channel_name' => $ch->title ?: ($ch->name ?? ''),
                ];
            }
        }

        // Fetch matching programmes across all mapped EPG channels, including those
        // that just finished (up to 24 hours ago) for discoverability.
        $cutoff = Carbon::now()->subHours(24);
        $programmes = EpgProgramme::whereIn('epg_channel_id', $epgChannelIds->toArray())
            ->where('start_time', '>=', $cutoff)
            ->whereRaw('LOWER(title) LIKE LOWER(?)', ['%'.$q.'%'])
            ->limit(500)
            ->get();

        // Group by SeriesKey-normalized title — this must match the normalization
        // in DvrRecordingRule::saving so rules created from these results will
        // match future programme data.
        $groups = [];
        foreach ($programmes as $p) {
            $norm = SeriesKey::normalize($p->title);

            if ($norm === '') {
                continue;
            }

            if (! isset($groups[$norm])) {
                $groups[$norm] = [
                    'display_title' => $p->title,
                    'programmes' => [],
                    'channel_ids' => [],
                ];
            }

            $groups[$norm]['programmes'][] = $p;
            $resolved = $channelLookup[$p->epg_channel_id] ?? null;
            if ($resolved) {
                $groups[$norm]['channel_ids'][$resolved['channel_id']] = $resolved;
            }
        }

        $results = [];
        foreach ($groups as $norm => $group) {
            /** @var array<int, EpgProgramme> $progs */
            $progs = $group['programmes'];

            // Upcoming airings first (soonest first), past as a tail. This list is
            // the per-episode recording picker on the TV client's show-detail screen
            // (m3u-tv#204), so a single descending-by-timestamp usort would bury
            // the next actionable airing behind farther-out ones (the bug being
            // fixed in #1411). Don't collapse this back to a single usort without
            // re-checking that case.
            $now = Carbon::now();
            $upcoming = [];
            $past = [];
            foreach ($progs as $p) {
                if ($p->start_time->gt($now)) {
                    $upcoming[] = $p;
                } else {
                    $past[] = $p;
                }
            }
            usort($upcoming, fn (EpgProgramme $a, EpgProgramme $b) => $a->start_time->timestamp <=> $b->start_time->timestamp);
            usort($past, fn (EpgProgramme $a, EpgProgramme $b) => $b->start_time->timestamp <=> $a->start_time->timestamp);
            $progs = [...$upcoming, ...$past];

            $channels = array_values($group['channel_ids']);

            // next_airing_at: earliest upcoming start_time, null if none. $upcoming
            // is already sorted ascending above, so the first entry is it.
            $nextAiringAt = $upcoming[0]->start_time ?? null;

            $recentEpisodes = array_slice(
                array_map(fn (EpgProgramme $p) => $this->formatEpisodePayload($p, $channelLookup), $progs),
                0,
                self::MAX_RECENT_EPISODES,
            );

            // airing_now: programmes currently in progress on EPG-mapped channels.
            // Every in-progress programme has a non-future start, so it lives in $past
            // (sorted most-recent-start first). Don't assume $past[0] is the answer -
            // the most recently started programme may have already ended (the test
            // covers exactly that case). Programmes with a null end_time cannot be
            // confirmed in progress and are excluded. Always emit an array so the
            // client doesn't need a null/empty branch.
            $airingNowProgs = array_values(array_filter(
                $past,
                fn (EpgProgramme $p) => $p->end_time !== null && $p->end_time->gt($now),
            ));
            $airingNow = array_map(
                fn (EpgProgramme $p) => $this->formatEpisodePayload($p, $channelLookup),
                $airingNowProgs,
            );

            $results[] = [
                'normalized_title' => $norm,
                'display_title' => $group['display_title'],
                'has_series_rule' => $seriesRuleTitles->has($norm),
                'series_rule_id' => $seriesRuleTitles[$norm] ?? null,
                'channel_count' => count($channels),
                'channels' => $channels,
                'episode_count' => count($progs),
                'next_airing_at' => $nextAiringAt?->toIso8601String(),
                'airing_now' => $airingNow,
                'recent_episodes' => $recentEpisodes,
            ];
        }

        // Sort: next-airing first, then most episodes.
        usort($results, function (array $a, array $b) {
            $aNext = $a['next_airing_at'];
            $bNext = $b['next_airing_at'];

            if ($aNext && ! $bNext) {
                return -1;
            }
            if (! $aNext && $bNext) {
                return 1;
            }
            if ($aNext && $bNext) {
                return $aNext <=> $bNext;
            }

            return $b['episode_count'] <=> $a['episode_count'];
        });

        return response()->json(array_slice($results, 0, 100));
    }

    /**
     * Build the per-episode payload used by both `recent_episodes[]` and `airing_now[]`
     * in `search_epg_shows`. The shapes must stay identical so the TV client can parse
     * either list with its existing `EpgShowEpisode.fromXtream` factory without needing
     * a second model.
     *
     * @param  array<string, array{channel_id: int, channel_name: string}>  $channelLookup
     * @return array{channel_id: int|null, channel_name: string|null, title: string, subtitle: ?string, start_time: string, end_time: ?string, season: mixed, episode: mixed, description: ?string}
     */
    private function formatEpisodePayload(EpgProgramme $p, array $channelLookup): array
    {
        $resolved = $channelLookup[$p->epg_channel_id] ?? null;

        return [
            'channel_id' => $resolved['channel_id'] ?? null,
            'channel_name' => $resolved['channel_name'] ?? null,
            'title' => $p->title,
            'subtitle' => $p->subtitle,
            'start_time' => $p->start_time->toIso8601String(),
            'end_time' => $p->end_time?->toIso8601String(),
            'season' => $p->season,
            'episode' => $p->episode,
            'description' => $p->description,
        ];
    }

    /**
     * Schedule a one-shot DVR recording rule from the TV app.
     */
    private function scheduleDvr(Request $request, $playlist, ?PlaylistAuth $playlistAuth): \Illuminate\Http\JsonResponse
    {
        $channelId = (int) $request->input('channel_id');
        $title = trim((string) $request->input('title', ''));
        $startTime = $request->input('start_time');
        $endTime = $request->input('end_time');

        if (! $channelId || ! $title || ! $startTime || ! $endTime) {
            return response()->json(['error' => 'channel_id, title, start_time, and end_time are required'], 400);
        }

        $dvrSetting = $playlist->dvrSetting?->enabled ? $playlist->dvrSetting : null;

        if (! $dvrSetting) {
            return response()->json(['error' => 'DVR is not enabled for this playlist'], 422);
        }

        if ($playlistAuth?->hasReachedConcurrentLimit()) {
            return response()->json(['error' => 'Concurrent recording limit reached'], 422);
        }

        $channel = $playlist->channels()->where('channels.id', $channelId)->first();
        if (! $channel) {
            return response()->json(['error' => 'Channel not found'], 404);
        }

        // manual_start/manual_end are cast as `datetime`, which Eloquent re-hydrates by
        // reinterpreting the stored wall-clock in `app.timezone` (not UTC) — see the same
        // compensation in EpgCacheService::cachePeriodProgrammes(). $startTime/$endTime
        // arrive as UTC ISO 8601 strings from the client, so we must convert them to
        // app.timezone's wall-clock before Eloquent formats them for storage, or the
        // round-trip re-read will reconstruct the wrong absolute instant.
        $appTz = config('app.timezone', 'UTC');
        $rule = DvrRecordingRule::create([
            'user_id' => $dvrSetting->user_id,
            'dvr_setting_id' => $dvrSetting->id,
            'playlist_auth_id' => $playlistAuth?->id,
            'type' => DvrRuleType::Manual,
            'channel_id' => $channelId,
            'series_title' => $title,
            'match_mode' => DvrMatchMode::Exact,
            'manual_start' => Carbon::parse($startTime)->setTimezone($appTz),
            'manual_end' => Carbon::parse($endTime)->setTimezone($appTz),
            'start_early_seconds' => (int) $request->input('start_early_seconds', $dvrSetting->default_start_early_seconds ?? 0),
            'end_late_seconds' => (int) $request->input('end_late_seconds', $dvrSetting->default_end_late_seconds ?? 0),
            'enabled' => true,
        ]);

        return response()->json([
            'success' => true,
            'rule_id' => $rule->id,
            'message' => "Recording scheduled: {$title}",
        ]);
    }

    /**
     * Create a series recording rule.
     *
     * Accepts the full option set (priority, padding, match mode, series mode,
     * keep_last). All new optional fields use omit-to-inherit: only fields the
     * caller actually sends are stored. `priority` falls back to its column
     * default (50 per the migration) when absent/blank/non-numeric; padding
     * fields fall back to runtime resolution via
     * DvrSetting::resolveStartEarlySeconds()/resolveEndLateSeconds(). Blank is
     * NOT coerced to 0 because 0 is a meaningful value for padding (no padding)
     * and is NOT the same as "inherit".
     *
     * Omitting both `channel_id` and `source_channel_id` is the explicit "any
     * channel" form — see DvrSchedulerService::resolveSeriesEpgScope() for the
     * scope resolution. `source_channel_id` is intentionally not accepted from
     * the API; setting it would narrow scope back to a single channel.
     *
     * Returns 409 with `rule_id` + `duplicate: true` when a Series rule for
     * the same normalized title already exists under this DVR setting (+ auth),
     * so the client can switch its button to the "rule exists" state rather
     * than showing a generic failure.
     *
     * Scheduling is handled by DvrRecordingRule::boot()'s created hook calling
     * scheduleRuleImmediately() for enabled rules; this action does NOT dispatch
     * DvrSchedulerTick (the model's hook already covers the API path).
     */
    private function createDvrSeriesRule(Request $request, $playlist, ?PlaylistAuth $playlistAuth): \Illuminate\Http\JsonResponse
    {
        $rawChannelId = $request->input('channel_id');
        $channelId = ($rawChannelId === null || $rawChannelId === '')
            ? null
            : (int) $rawChannelId;
        $title = trim((string) $request->input('title', ''));

        if (! $title) {
            return response()->json(['error' => 'title is required'], 400);
        }

        $dvrSetting = $playlist->dvrSetting?->enabled ? $playlist->dvrSetting : null;

        if (! $dvrSetting) {
            return response()->json(['error' => 'DVR is not enabled for this playlist'], 422);
        }

        // Duplicate guard: same dvr_setting, same normalized show title, same auth.
        // Uses the auto-derived `normalized_title` column that DvrRecordingRule::saving
        // populates via SeriesKey::normalize — matching the stored column (not
        // re-computing with a different normalizer) so future EPG matches align.
        $normalizedTitle = SeriesKey::normalize($title);
        $existing = DvrRecordingRule::where('dvr_setting_id', $dvrSetting->id)
            ->where('type', DvrRuleType::Series)
            ->where('normalized_title', $normalizedTitle)
            ->when($playlistAuth, fn ($q) => $q->where('playlist_auth_id', $playlistAuth->id))
            ->first();

        if ($existing) {
            return response()->json([
                'error' => 'A series rule for this show already exists',
                'rule_id' => $existing->id,
                'duplicate' => true,
            ], 409);
        }

        if ($channelId !== null) {
            $channel = $playlist->channels()->where('channels.id', $channelId)->first();
            if (! $channel) {
                return response()->json(['error' => 'Channel not found'], 404);
            }
        }

        $matchMode = DvrMatchMode::tryFrom($request->input('match_mode', 'contains')) ?? DvrMatchMode::Contains;
        $seriesMode = DvrSeriesMode::tryFrom($request->input('series_mode', $dvrSetting->default_series_mode?->value ?? 'all')) ?? DvrSeriesMode::All;
        // Same blank-vs-meaningful-zero discipline as the new optional fields: a blank
        // or non-numeric value falls through to the DVR setting default instead of being
        // coerced to 0 (which would silently disable the keep-last policy).
        $rawKeepLast = $request->input('keep_last');
        $keepLast = is_numeric($rawKeepLast)
            ? (int) $rawKeepLast
            : $dvrSetting->default_series_keep_last;

        $createAttrs = [
            'user_id' => $dvrSetting->user_id,
            'dvr_setting_id' => $dvrSetting->id,
            'playlist_auth_id' => $playlistAuth?->id,
            'type' => DvrRuleType::Series,
            'channel_id' => $channelId,
            'series_title' => $title,
            'match_mode' => $matchMode,
            'series_mode' => $seriesMode,
            // Keep the legacy `new_only` flag in lockstep with series_mode so the
            // DvrRecordingRule::saving hook's new_only→series_mode migration (which
            // rewrites series_mode=new_flag to all when new_only is false) does not
            // clobber an explicitly-requested new_flag. Must be EXACTLY this
            // equality: new_only=true alongside series_mode=unique_se would trip the
            // hook's first branch and clobber unique_se → new_flag.
            'new_only' => ($seriesMode === DvrSeriesMode::NewFlag),
            'keep_last' => $keepLast,
            'enabled' => true,
        ];

        // Omit-to-inherit for all three new optional fields. Absent or blank
        // leaves the key OUT of the create array so the column default (priority=50
        // per the migration) or runtime resolution via DvrSetting::resolveStartEarly
        // Seconds()/resolveEndLateSeconds() applies. Critically, blank is NOT
        // coerced to 0 because 0 is a meaningful value for the padding fields
        // (no padding) and is NOT the same as "inherit" — see the spec.
        $rawPriority = $request->input('priority');
        if (is_numeric($rawPriority)) {
            $createAttrs['priority'] = max(0, min(100, (int) $rawPriority));
        }

        $rawStartEarly = $request->input('start_early_seconds');
        if ($rawStartEarly !== null && $rawStartEarly !== '') {
            $createAttrs['start_early_seconds'] = (int) $rawStartEarly;
        }
        $rawEndLate = $request->input('end_late_seconds');
        if ($rawEndLate !== null && $rawEndLate !== '') {
            $createAttrs['end_late_seconds'] = (int) $rawEndLate;
        }

        $rule = DvrRecordingRule::create($createAttrs);

        return response()->json([
            'success' => true,
            'rule_id' => $rule->id,
        ]);
    }

    /**
     * Update an existing Series DVR recording rule in place.
     *
     * This intentionally does NOT delete-and-recreate: deleting a rule cascades to
     * its recordings and would destroy recording history and change the rule id.
     *
     * **Omit-to-inherit on update** — only fields actually present in the request are
     * applied; absent fields keep their current value. Nothing is nulled out unless
     * the request explicitly says so (see `channel_id` below).
     */
    private function updateDvrSeriesRule(Request $request, $playlist, ?PlaylistAuth $playlistAuth): \Illuminate\Http\JsonResponse
    {
        $ruleId = (int) $request->input('rule_id');

        if (! $ruleId) {
            return response()->json(['error' => 'rule_id parameter is required'], 400);
        }

        $dvrSetting = $playlist->dvrSetting;

        if (! $dvrSetting) {
            return response()->json(['error' => 'DVR not configured for this playlist'], 404);
        }

        $rule = DvrRecordingRule::where('dvr_setting_id', $dvrSetting->id)
            ->where('id', $ruleId)
            ->where('type', DvrRuleType::Series)
            ->when($playlistAuth, fn ($q) => $q->where('playlist_auth_id', $playlistAuth->id))
            ->first();

        if (! $rule) {
            return response()->json(['error' => 'Series rule not found'], 404);
        }

        // channel_id: present-and-blank means "any channel" (null); present-with-a-value
        // pins to that channel; absent means "leave unchanged". `$request->has` returns
        // false for a blank value, so check presence via `$request->input` + `exists`.
        if ($request->exists('channel_id')) {
            $rawChannelId = $request->input('channel_id');
            $channelId = ($rawChannelId === null || $rawChannelId === '')
                ? null
                : (int) $rawChannelId;

            if ($channelId !== null) {
                $channel = $playlist->channels()->where('channels.id', $channelId)->first();
                if (! $channel) {
                    return response()->json(['error' => 'Channel not found'], 404);
                }
            }

            $rule->channel_id = $channelId;
        }

        if ($request->has('match_mode')) {
            $matchMode = DvrMatchMode::tryFrom($request->input('match_mode'));
            if ($matchMode !== null) {
                $rule->match_mode = $matchMode;
            }
        }

        if ($request->has('series_mode')) {
            $seriesMode = DvrSeriesMode::tryFrom($request->input('series_mode'));
            if ($seriesMode !== null) {
                $rule->series_mode = $seriesMode;
                // Keep the legacy new_only flag in lockstep with series_mode for the same
                // saving-hook migration reason as createDvrSeriesRule (task 16).
                $rule->new_only = ($seriesMode === DvrSeriesMode::NewFlag);
            }
        }

        $rawKeepLast = $request->input('keep_last');
        if (is_numeric($rawKeepLast)) {
            $rule->keep_last = (int) $rawKeepLast;
        }

        $rawPriority = $request->input('priority');
        if (is_numeric($rawPriority)) {
            $rule->priority = max(0, min(100, (int) $rawPriority));
        }

        $rawStartEarly = $request->input('start_early_seconds');
        if ($rawStartEarly !== null && $rawStartEarly !== '') {
            $rule->start_early_seconds = (int) $rawStartEarly;
        }

        $rawEndLate = $request->input('end_late_seconds');
        if ($rawEndLate !== null && $rawEndLate !== '') {
            $rule->end_late_seconds = (int) $rawEndLate;
        }

        $rule->save();

        return response()->json([
            'success' => true,
            'rule_id' => $rule->id,
        ]);
    }

    /**
     * Cancel a scheduled or in-progress DVR recording.
     */
    private function cancelDvrRecording(Request $request, $playlist, ?PlaylistAuth $playlistAuth): \Illuminate\Http\JsonResponse
    {
        $uuid = $request->input('recording_id');

        if (! $uuid) {
            return response()->json(['error' => 'recording_id parameter is required'], 400);
        }

        $dvrSetting = $playlist->dvrSetting;

        if (! $dvrSetting) {
            return response()->json(['error' => 'DVR not configured for this playlist'], 404);
        }

        $recording = DvrRecording::where('dvr_setting_id', $dvrSetting->id)
            ->where('uuid', $uuid)
            ->when($playlistAuth, fn ($q) => $q->where('playlist_auth_id', $playlistAuth->id))
            ->whereIn('status', [DvrRecordingStatus::Scheduled, DvrRecordingStatus::Recording])
            ->first();

        if (! $recording) {
            return response()->json(['error' => 'Recording not found or not cancellable'], 404);
        }

        app(DvrRecorderService::class)->cancel($recording);

        return response()->json(['success' => true]);
    }

    /**
     * Delete a completed, failed, cancelled, or post-processing DVR recording.
     *
     * PostProcessing is included alongside the terminal statuses because
     * DvrRecorderService::cancel() now routes a recording that had already
     * captured footage through post-processing (so a "kept" cancelled
     * recording ends up Completed/playable) rather than marking it Cancelled
     * immediately — the TV app's "Delete recording" choice calls cancel then
     * delete back-to-back, so by the time this runs such a recording is
     * already in PostProcessing, not Cancelled.
     *
     * A PostProcessing recording deleted this way almost always still has its
     * proxy_network_id set - the post-processing job that would normally free
     * those resources hasn't had a chance to run yet. releaseProxyResources()
     * frees them here instead of leaving them orphaned on the proxy.
     */
    private function deleteDvrRecording(Request $request, $playlist, ?PlaylistAuth $playlistAuth): \Illuminate\Http\JsonResponse
    {
        $uuid = $request->input('recording_id');

        if (! $uuid) {
            return response()->json(['error' => 'recording_id parameter is required'], 400);
        }

        $dvrSetting = $playlist->dvrSetting;

        if (! $dvrSetting) {
            return response()->json(['error' => 'DVR not configured for this playlist'], 404);
        }

        $recording = DvrRecording::where('dvr_setting_id', $dvrSetting->id)
            ->where('uuid', $uuid)
            ->when($playlistAuth, fn ($q) => $q->where('playlist_auth_id', $playlistAuth->id))
            ->whereIn('status', [
                DvrRecordingStatus::Completed,
                DvrRecordingStatus::Failed,
                DvrRecordingStatus::Cancelled,
                DvrRecordingStatus::PostProcessing,
            ])
            ->first();

        if (! $recording) {
            return response()->json(['error' => 'Recording not found or not deletable'], 404);
        }

        app(DvrRecorderService::class)->releaseProxyResources($recording);

        $recording->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Format a DvrRecording into the API response shape.
     */
    private function formatDvrRecording(DvrRecording $recording, string $username, string $password, bool $full = false): array
    {
        $isRecording = $recording->status === DvrRecordingStatus::Recording;
        $isCompleted = $recording->status === DvrRecordingStatus::Completed;

        $format = $isCompleted
            ? ($recording->dvrSetting?->dvr_output_format ?? 'mp4')
            : 'mp4';

        $baseUrl = config('app.url');

        $streamUrl = $isCompleted
            ? "{$baseUrl}/dvr/{$username}/{$password}/{$recording->uuid}.{$format}"
            : null;

        $liveUrl = $isRecording
            ? "{$baseUrl}/dvr/{$username}/{$password}/{$recording->uuid}/live.m3u8"
            : null;

        $hasEdl = $isCompleted && ($recording->dvrSetting?->enable_comskip ?? false);
        $edlUrl = $hasEdl
            ? "{$baseUrl}/dvr/{$username}/{$password}/{$recording->uuid}/edl"
            : null;

        $data = [
            'uuid' => $recording->uuid,
            'title' => $recording->title,
            'subtitle' => $recording->subtitle,
            'status' => $recording->status->value,
            'channel_name' => $recording->channel?->title ?? $recording->channel?->name,
            'channel_id' => $recording->channel_id,
            'scheduled_start' => $recording->scheduled_start?->toIso8601String(),
            'scheduled_end' => $recording->scheduled_end?->toIso8601String(),
            'actual_start' => $recording->actual_start?->toIso8601String(),
            'actual_end' => $recording->actual_end?->toIso8601String(),
            'duration_seconds' => $recording->duration_seconds,
            'file_size_bytes' => $recording->file_size_bytes,
            'season' => $recording->season,
            'episode' => $recording->episode,
            'stream_url' => $streamUrl,
            'live_url' => $liveUrl,
            'edl_url' => $edlUrl,
            'has_edl' => $hasEdl,
        ];

        if ($full) {
            $data['metadata'] = $recording->metadata;
            $data['epg_programme_data'] = $recording->epg_programme_data;
            $data['error_message'] = $recording->error_message;
        }

        return $data;
    }

    /**
     * Format a DvrRecordingRule (series rule) into the API response shape.
     */
    private function formatDvrSeriesRule(DvrRecordingRule $rule): array
    {
        return [
            'id' => $rule->id,
            'channel_id' => $rule->channel_id,
            'channel_name' => $rule->channel?->title ?? $rule->channel?->name,
            'series_title' => $rule->series_title,
            'match_mode' => $rule->match_mode->value,
            'series_mode' => $rule->series_mode->value,
            'keep_last' => $rule->keep_last,
            'priority' => $rule->priority,
            'enabled' => (bool) $rule->enabled,
            'enable_comskip' => (bool) $rule->enable_comskip,
            'start_early_seconds' => $rule->start_early_seconds,
            'end_late_seconds' => $rule->end_late_seconds,
            'created_at' => $rule->created_at?->toIso8601String(),
            'updated_at' => $rule->updated_at?->toIso8601String(),
            'recording_count' => $rule->recordings_count,
        ];
    }

    /**
     * Determine whether Xtream clients should be told request integrations are available.
     */
    private function requestsFeatureEnabled($playlist, string $authMethod, ?PlaylistAuth $playlistAuth): bool
    {
        if ($authMethod !== 'playlist_auth' || ! ($playlistAuth?->request_enabled)) {
            return false;
        }

        $effectivePlaylist = $this->resolveEffectivePlaylist($playlist);

        if (! $effectivePlaylist) {
            return false;
        }

        if (! $effectivePlaylist->requestSetting?->enabled) {
            return false;
        }

        return ArrIntegration::where('user_id', $effectivePlaylist->user_id)
            ->enabled()
            ->guestEnabled()
            ->exists();
    }

    private function searchRequests(
        Request $request,
        mixed $playlist,
        string $authMethod,
        ?PlaylistAuth $playlistAuth,
    ): JsonResponse {
        $effectivePlaylist = $this->authorizedRequestPlaylist($playlist, $authMethod, $playlistAuth);
        if (! $effectivePlaylist) {
            return $this->requestError(
                'request_access_denied',
                'Content requests are not available for these credentials.',
                403,
            );
        }

        $validator = Validator::make($request->all(), [
            'query' => ['required', 'string', 'min:2', 'max:100'],
            'type' => ['nullable', 'string', 'in:movie,series'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        if ($validator->fails()) {
            return $this->requestValidationError($validator->errors()->keys());
        }

        $validated = $validator->validated();

        $search = app(ContentRequestService::class)->search(
            $effectivePlaylist,
            $validated['query'],
            $validated['type'] ?? null,
        );
        if ($search['searched_providers'] > 0
            && $search['unavailable_providers'] === $search['searched_providers']) {
            return $this->requestError(
                'providers_unavailable',
                'All request providers are temporarily unavailable.',
                503,
            );
        }

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $total = count($search['results']);
        $results = array_slice($search['results'], ($page - 1) * $perPage, $perPage);

        return $this->requestSuccess(
            ['results' => $results],
            [
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                ],
                'partial' => $search['unavailable_providers'] > 0,
                'unavailable_providers' => $search['unavailable_providers'],
            ],
        );
    }

    /** @param array<string, mixed> $data */
    private function requestSuccess(array $data, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = [
            'api_version' => 1,
            'data' => $data,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    private function requestError(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'api_version' => 1,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }

    /** @param array<int, string> $fields */
    private function requestValidationError(array $fields): JsonResponse
    {
        return response()->json([
            'api_version' => 1,
            'error' => [
                'code' => 'invalid_request',
                'message' => 'The request parameters are invalid.',
                'fields' => $fields,
            ],
        ], 422);
    }

    private function requestRateLimit(
        string $action,
        mixed $playlist,
        string $authMethod,
        ?PlaylistAuth $playlistAuth,
    ): ?JsonResponse {
        $limit = match ($action) {
            'request_search' => 20,
            'request_submit' => 5,
            'request_history' => 60,
            'request_status' => 120,
            'request_dismiss' => 20,
            default => null,
        };

        if ($limit === null
            || ! $playlistAuth
            || ! $this->requestsFeatureEnabled($playlist, $authMethod, $playlistAuth)) {
            return null;
        }

        $key = "xtream-request:{$action}:{$playlistAuth->id}";
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retryAfter = RateLimiter::availableIn($key);

            return $this->requestError(
                'rate_limited',
                'Too many requests. Please try again later.',
                429,
            )->header('Retry-After', (string) $retryAfter);
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    private function submitRequest(
        Request $request,
        mixed $playlist,
        string $authMethod,
        ?PlaylistAuth $playlistAuth,
    ): JsonResponse {
        $effectivePlaylist = $this->authorizedRequestPlaylist($playlist, $authMethod, $playlistAuth);
        if (! $effectivePlaylist || ! $playlistAuth) {
            return $this->requestError(
                'request_access_denied',
                'Content requests are not available for these credentials.',
                403,
            );
        }

        $validator = Validator::make($request->all(), [
            'type' => ['required', 'string', 'in:movie,series'],
            'integration_id' => ['required', 'integer', 'min:1'],
            'external_id' => ['required', 'integer', 'min:1'],
            'seasons' => ['nullable', 'array', 'min:1'],
            'seasons.*' => ['integer', 'min:0', 'distinct'],
        ]);
        if ($validator->fails()) {
            return $this->requestValidationError($validator->errors()->keys());
        }

        $validated = $validator->validated();

        $result = app(ContentRequestService::class)->submit(
            $effectivePlaylist,
            $playlistAuth,
            $validated['type'],
            (int) $validated['integration_id'],
            (int) $validated['external_id'],
            $validated['seasons'] ?? null,
        );

        if (! $result['ok']) {
            $status = match ($result['code'] ?? null) {
                'already_requested', 'already_available' => 409,
                'not_found' => 404,
                'provider_unavailable' => 503,
                'submission_failed' => 502,
                default => 422,
            };

            return $this->requestError($result['code'], $result['error'], $status);
        }

        return $this->requestSuccess([
            'status' => $result['status'],
            'request' => $result['request'],
        ], status: $result['status'] === 'pending_approval' ? 201 : 200);
    }

    private function requestHistory(
        Request $request,
        mixed $playlist,
        string $authMethod,
        ?PlaylistAuth $playlistAuth,
    ): JsonResponse {
        if (! $this->authorizedRequestPlaylist($playlist, $authMethod, $playlistAuth) || ! $playlistAuth) {
            return $this->requestError(
                'request_access_denied',
                'Content requests are not available for these credentials.',
                403,
            );
        }

        $validator = Validator::make($request->all(), [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        if ($validator->fails()) {
            return $this->requestValidationError($validator->errors()->keys());
        }

        $validated = $validator->validated();
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $history = app(ContentRequestService::class)->history($playlistAuth, $page, $perPage);

        return $this->requestSuccess(
            ['requests' => $history['requests']],
            ['pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $history['total'],
                'last_page' => max(1, (int) ceil($history['total'] / $perPage)),
            ]],
        );
    }

    private function requestStatus(
        Request $request,
        mixed $playlist,
        string $authMethod,
        ?PlaylistAuth $playlistAuth,
    ): JsonResponse {
        if (! $this->authorizedRequestPlaylist($playlist, $authMethod, $playlistAuth) || ! $playlistAuth) {
            return $this->requestError(
                'request_access_denied',
                'Content requests are not available for these credentials.',
                403,
            );
        }

        $validator = Validator::make($request->all(), [
            'request_id' => ['required', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->requestValidationError($validator->errors()->keys());
        }

        $mediaRequest = app(ContentRequestService::class)->status(
            $playlistAuth,
            (int) $validator->validated()['request_id'],
        );
        if (! $mediaRequest) {
            return $this->requestError('request_not_found', 'The request was not found.', 404);
        }

        return $this->requestSuccess(['request' => $mediaRequest]);
    }

    private function dismissRequest(
        Request $request,
        mixed $playlist,
        string $authMethod,
        ?PlaylistAuth $playlistAuth,
    ): JsonResponse {
        if (! $this->authorizedRequestPlaylist($playlist, $authMethod, $playlistAuth) || ! $playlistAuth) {
            return $this->requestError(
                'request_access_denied',
                'Content requests are not available for these credentials.',
                403,
            );
        }

        $validator = Validator::make($request->all(), [
            'request_id' => ['required', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->requestValidationError($validator->errors()->keys());
        }

        $requestId = (int) $validator->validated()['request_id'];
        $result = app(ContentRequestService::class)->dismiss($playlistAuth, $requestId);
        if (! $result['ok']) {
            return match ($result['code']) {
                'request_not_dismissible' => $this->requestError(
                    'request_not_dismissible',
                    'Only completed or rejected requests can be dismissed.',
                    409,
                ),
                default => $this->requestError('request_not_found', 'The request was not found.', 404),
            };
        }

        return $this->requestSuccess([
            'dismissed' => true,
            'request_id' => $requestId,
        ]);
    }

    private function authorizedRequestPlaylist(
        mixed $playlist,
        string $authMethod,
        ?PlaylistAuth $playlistAuth,
    ): Playlist|CustomPlaylist|MergedPlaylist|null {
        if (! $this->requestsFeatureEnabled($playlist, $authMethod, $playlistAuth)) {
            return null;
        }

        return $this->resolveEffectivePlaylist($playlist);
    }

    /**
     * Authenticate the user based on the provided credentials.
     *
     * This method checks for PlaylistAuth credentials first, then falls back to
     * the original authentication method using username and password.
     *
     * @return array|bool Returns an array with playlist and auth method, or false if authentication fails.
     */
    private function authenticate(Request $request)
    {
        $username = $request->input('username');
        $password = $request->input('password');

        return PlaylistFacade::authenticate($username, $password);
    }

    private function formatRuntimeFromSeconds(?int $seconds): ?string
    {
        if (! $seconds || $seconds <= 0) {
            return null;
        }
        $minutes = (int) round($seconds / 60);
        if ($minutes >= 60) {
            $h = intdiv($minutes, 60);
            $m = $minutes % 60;

            return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
        }

        return "{$minutes} min";
    }

    /**
     * The proxy feature is advertised when the playlist owner may use the proxy
     * and, for PlaylistAuth credentials, the individual auth has proxy access
     * enabled. Owner/alias credentials act with the owner's own permission.
     */
    private function canAdvertiseProxyFeature($playlist, string $authMethod, ?PlaylistAuth $playlistAuth): bool
    {
        if (! $playlist->user?->canUseProxy()) {
            return false;
        }

        if ($authMethod === 'playlist_auth') {
            return (bool) $playlistAuth?->proxy_enabled;
        }

        return true;
    }

    /**
     * Build the proxy payload for the auth response: whether the proxy is forced
     * at the playlist level, and the transcoding profiles the authenticated user
     * may apply to proxied streams. Profile ffmpeg args are intentionally never
     * exposed to clients.
     *
     * When 'forced' is true the playlist already routes every stream through the
     * proxy, so clients should present the proxy as locked on — profile selection
     * still applies.
     *
     * @return array{forced: bool, profiles: array<int, array{id: int, name: string, description: string|null, format: string|null}>}|array{}
     */
    private function resolveProxyData($playlist, array $features, string $authMethod, ?PlaylistAuth $playlistAuth): array
    {
        if (! in_array('proxy', $features)) {
            return [];
        }

        $forced = (bool) ($playlist->enable_proxy ?? false);

        $query = StreamProfile::where('user_id', $playlist->user_id)->orderBy('name');

        if ($authMethod === 'playlist_auth') {
            $access = $playlistAuth->proxy_profile_access ?? 'all';
            if ($access === 'none') {
                return ['forced' => $forced, 'profiles' => []];
            }
            if ($access === 'selected') {
                $allowedIds = array_map('intval', $playlistAuth->proxy_stream_profile_ids ?? []);
                if (empty($allowedIds)) {
                    return ['forced' => $forced, 'profiles' => []];
                }
                $query->whereIn('id', $allowedIds);
            }
        }

        return [
            'forced' => $forced,
            'profiles' => $query->get(['id', 'name', 'description', 'format'])
                ->map(fn (StreamProfile $profile) => [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'description' => $profile->description,
                    'format' => $profile->format,
                ])
                ->values()
                ->all(),
        ];
    }
}
