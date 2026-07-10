<?php

namespace App\Http\Controllers\Api;

use App\DataObjects\ClientCapabilities;
use App\Facades\PlaylistFacade;
use App\Http\Controllers\Controller;
use App\Http\Controllers\XtreamStreamController;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Episode;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\StreamProfile;
use App\Models\TvNotification;
use App\Services\M3uProxyService;
use App\Services\PlaybackCapabilityService;
use App\Services\StreamProfileRuleEvaluator;
use App\Services\StreamStatsService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TvApiController extends Controller
{
    /**
     * GET /api/tv/{username}/{password}/notifications
     *
     * Returns unread TV notifications for the authenticated playlist.
     * Admin-scope sessions (owner_auth + isAdmin) also see admin_only notifications.
     * Pass optional `channels[]` query param to filter by notification channel.
     * Also returns Reverb connection config so the TV app can subscribe.
     */
    public function notifications(Request $request): JsonResponse
    {
        $auth = $this->resolveAuth($request);
        $playlist = $auth['playlist'];

        $query = TvNotification::where('notifiable_type', $playlist->getMorphClass())
            ->where('notifiable_id', $playlist->id)
            ->when(! $auth['isAdmin'], fn ($q) => $q->where('admin_only', 0))
            ->whereNull('read_at')
            ->latest()
            ->limit(50);

        if ($request->filled('channels')) {
            $query->whereIn('channel', (array) $request->input('channels'));
        }

        $scheme = config('broadcasting.connections.reverb.options.scheme', 'http');

        $configuredChannels = collect(app(GeneralSettings::class)->tv_notification_channels)
            ->map(fn (array $c) => [
                'name' => $c['name'] ?? '',
                'label' => $c['label'] ?? '',
            ])
            ->filter(fn (array $c) => $c['name'] !== '')
            ->values();

        return response()->json([
            'notifiable_id' => $playlist->id,
            'notifiable_type' => $playlist->getMorphClass(),
            'is_admin' => $auth['isAdmin'],
            'notifications' => $query->get(),
            'available_channels' => $configuredChannels,
            'reverb' => [
                'host' => config('broadcasting.connections.reverb.options.host', 'localhost'),
                'port' => (int) config('broadcasting.connections.reverb.options.port', 36800),
                'scheme' => $scheme === 'https' ? 'wss' : 'ws',
                'app_key' => config('broadcasting.connections.reverb.key'),
                'channel' => $auth['channel'],
            ],
        ]);
    }

    /**
     * POST /api/tv/{username}/{password}/notifications/{id}/read
     *
     * Marks a single TV notification as read. Verifies playlist ownership.
     * Non-admin sessions cannot mark admin_only notifications as read.
     */
    public function markRead(Request $request): JsonResponse
    {
        $auth = $this->resolveAuth($request);
        $playlist = $auth['playlist'];
        $id = $request->route('id');

        $notification = TvNotification::where('id', $id)
            ->where('notifiable_type', $playlist->getMorphClass())
            ->where('notifiable_id', $playlist->id)
            ->when(! $auth['isAdmin'], fn ($q) => $q->where('admin_only', 0))
            ->firstOrFail();

        $notification->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/tv/{username}/{password}/broadcasting/auth
     *
     * Custom Pusher channel auth for TV app WebSocket subscriptions.
     * Bypasses the session-based /broadcasting/auth since TV clients use
     * Xtream credentials (no user session / Sanctum token).
     */
    public function broadcastingAuth(Request $request): JsonResponse
    {
        $auth = $this->resolveAuth($request);

        abort_if($request->input('channel_name') !== $auth['channel'], 403, 'Forbidden channel.');

        $sig = hash_hmac(
            'sha256',
            "{$request->input('socket_id')}:{$request->input('channel_name')}",
            config('broadcasting.connections.reverb.secret')
        );

        return response()->json([
            'auth' => config('broadcasting.connections.reverb.key').':'.$sig,
        ]);
    }

    public function playStream(
        Request $request,
        string $playlistType,
        int $playlistId,
        string $type,
        int $streamId,
    ): Response {
        $playlistClass = match ($playlistType) {
            'playlist' => Playlist::class,
            'merged' => MergedPlaylist::class,
            'custom' => CustomPlaylist::class,
            'alias' => PlaylistAlias::class,
        };
        $playlist = $playlistClass::findOrFail($playlistId);
        abort_if($playlist instanceof PlaylistAlias && $playlist->isExpired(), 403);
        abort_if($this->loadStream($playlist, $streamId, $type) === null, 404);

        $authMethod = (string) $request->query('auth_method');
        abort_unless(in_array($authMethod, ['owner_auth', 'playlist_auth', 'alias_auth'], true), 403);
        $playlistAuthId = $request->integer('auth');
        $playlistAuth = $authMethod === 'playlist_auth' && $playlistAuthId > 0
            ? PlaylistAuth::query()
                ->whereKey($playlistAuthId)
                ->where('enabled', true)
                ->whereHas('assignedPlaylist', fn ($query) => $query
                    ->where('authenticatable_type', $playlist->getMorphClass())
                    ->where('authenticatable_id', $playlist->id))
                ->first()
            : null;
        abort_if($authMethod === 'playlist_auth' && ($playlistAuth === null || $playlistAuth->isExpired()), 403);
        abort_if($authMethod === 'alias_auth' && ! $playlist instanceof PlaylistAlias, 403);

        $username = match ($authMethod) {
            'playlist_auth' => $playlistAuth->username,
            'alias_auth' => $playlist->username,
            default => $playlist->user->name,
        };
        $password = match ($authMethod) {
            'playlist_auth' => $playlistAuth->password,
            'alias_auth' => $playlist->password,
            default => $playlist->uuid,
        };
        abort_if(! $username || ! $password, 403);
        abort_unless($playlist->user?->canUseProxy() && filled(config('proxy.m3u_proxy_host')), 503);

        $format = $request->query('format');
        $request->merge(['proxy' => 'true']);
        $controller = app(XtreamStreamController::class);

        try {
            return match ($type) {
                'live' => $controller->handleLive(
                    $request,
                    $username,
                    $password,
                    $streamId,
                    $format,
                ),
                'vod' => $controller->handleVod(
                    $request,
                    $username,
                    $password,
                    $streamId,
                    $format,
                ),
                'series' => $controller->handleSeries(
                    $request,
                    $username,
                    $password,
                    $streamId,
                    $format,
                ),
                'catchup' => $controller->handleTimeshift(
                    $request,
                    $username,
                    $password,
                    $request->integer('duration'),
                    (string) $request->query('start'),
                    $streamId,
                    $format,
                ),
            };
        } catch (Throwable) {
            abort(503);
        }
    }

    public function resolveStream(Request $request): JsonResponse
    {
        $auth = $this->resolveAuth($request);
        $playlist = $auth['playlist'];

        $validated = $request->validate([
            'type' => 'required|in:live,vod,series,catchup',
            'stream_id' => 'required|integer|min:1',
            'catchup_start' => 'required_if:type,catchup|date',
            'catchup_duration_minutes' => 'required_if:type,catchup|integer|min:1|max:10080',
            'catchup_format' => 'sometimes|string|in:ts,m3u8',
            'extension' => 'prohibited',
            'client_capabilities' => 'sometimes|array',
            'client_capabilities.profile' => 'sometimes|string|max:255',
            'client_capabilities.platform' => 'sometimes|string|max:255',
            'client_capabilities.backend' => 'sometimes|string|max:255',
            'client_capabilities.video_codecs' => 'sometimes|array',
            'client_capabilities.video_codecs.*' => 'string|max:50',
            'client_capabilities.audio_codecs' => 'sometimes|array',
            'client_capabilities.audio_codecs.*' => 'string|max:50',
            'client_capabilities.containers' => 'sometimes|array',
            'client_capabilities.containers.*' => 'string|max:50',
            'client_capabilities.max_height' => 'sometimes|integer|min:1',
            'client_capabilities.max_bitrate_kbps' => 'sometimes|integer|min:1',
            'client_capabilities.hdr' => 'sometimes|boolean',
        ]);

        $type = $validated['type'];
        $streamId = (int) $validated['stream_id'];
        $directContext = $type === 'catchup' ? [
            'start' => Carbon::parse($validated['catchup_start'])->utc()->format('Y-m-d:H-i-s'),
            'duration' => (int) $validated['catchup_duration_minutes'],
            'format' => $validated['catchup_format'] ?? 'ts',
        ] : [];

        $stream = $this->loadStream($playlist, $streamId, $type);

        if (! $stream) {
            return response()->json(['error' => 'Stream not found'], 404);
        }

        $streamStats = $stream->stream_stats;
        $sourceInfo = $this->extractSourceInfo($streamStats);

        if (! $request->has('client_capabilities') || ! is_array($request->input('client_capabilities'))) {
            $url = $this->getDirectStreamUrl(
                $stream,
                $playlist,
                $type,
                $auth['auth_method'],
                $auth['playlist_auth_id'],
                $directContext,
            );

            Log::info('TV stream resolve (no capabilities)', [
                'type' => $type,
                'stream_id' => $streamId,
                'mode' => 'direct_play',
                'reason' => 'No client capabilities provided',
            ]);

            return response()->json([
                'mode' => 'direct_play',
                'url' => $url,
                'reason' => 'No client capabilities provided',
                'source' => $sourceInfo,
            ]);
        }

        $capabilities = ClientCapabilities::fromArray($request->input('client_capabilities'));
        $profile = $this->resolveStreamProfile($stream, $playlist);
        $transcodeOutput = PlaybackCapabilityService::inspectTranscodeOutput($profile);
        $canTranscode = $type !== 'catchup'
            && $profile !== null
            && $playlist->user?->canUseProxy()
            && ! empty(config('proxy.m3u_proxy_host'));

        $decision = PlaybackCapabilityService::decide(
            $capabilities,
            $streamStats,
            $canTranscode,
            $transcodeOutput,
        );

        $result = match ($decision['mode']) {
            'direct_play' => [
                'mode' => 'direct_play',
                'url' => $this->getDirectStreamUrl(
                    $stream,
                    $playlist,
                    $type,
                    $auth['auth_method'],
                    $auth['playlist_auth_id'],
                    $directContext,
                ),
                'reason' => $decision['reason'],
                'source' => $decision['source'],
            ],
            'transcode' => $this->tryCreateTranscodedStream($stream, $playlist, $profile, $request, $decision),
            'unsupported' => [
                'mode' => 'unsupported',
                'url' => null,
                'reason' => $decision['reason'],
                'source' => $decision['source'],
            ],
        };

        Log::info('TV stream resolve', [
            'type' => $type,
            'stream_id' => $streamId,
            'mode' => $result['mode'],
            'reason' => $result['reason'],
            'source_video_codec' => $sourceInfo['video_codec'],
            'source_audio_codec' => $sourceInfo['audio_codec'],
            'source_container' => $sourceInfo['container'],
        ]);

        return response()->json($result);
    }

    private function loadStream(Model $playlist, int $streamId, string $type): Channel|Episode|null
    {
        if ($type === 'live' || $type === 'vod' || $type === 'catchup') {
            $channel = $playlist->channels()
                ->where('channels.id', $streamId)
                ->where('enabled', true)
                ->where('is_vod', $type === 'vod')
                ->first();

            if ($type === 'catchup' && (! $channel || ! $this->supportsCatchup($channel))) {
                return null;
            }

            return $channel;
        }

        if ($type === 'series') {
            $episode = Episode::with('series')->find($streamId);
            if (! $episode || ! $episode->enabled) {
                return null;
            }

            $series = $episode->series;
            if (! $series || ! $series->enabled) {
                return null;
            }

            $isMember = $playlist->series()
                ->where('series.id', $series->id)
                ->exists();

            return $isMember ? $episode : null;
        }

        return null;
    }

    private function supportsCatchup(Channel $channel): bool
    {
        if ($channel->playlist?->disable_catchup) {
            return false;
        }

        if ((! empty($channel->catchup) && $channel->catchup !== '0') || (int) $channel->shift > 0) {
            return true;
        }

        return $channel->failoverChannels()
            ->with('playlist')
            ->where('enabled', true)
            ->get()
            ->contains(fn (Channel $failover): bool => ! $failover->playlist?->disable_catchup
                && ((! empty($failover->catchup) && $failover->catchup !== '0') || (int) $failover->shift > 0));
    }

    private function resolveStreamProfile(Channel|Episode $stream, Model $playlist): ?StreamProfile
    {
        if ($stream instanceof Channel) {
            $playlist->loadMissing('streamProfile', 'vodStreamProfile');

            $profile = $stream->streamProfile
                ?? ($stream->is_vod ? $playlist->vodStreamProfile : $playlist->streamProfile);

            if (! $profile) {
                $defaultId = $stream->is_vod
                    ? app(GeneralSettings::class)->default_vod_stream_profile_id
                    : app(GeneralSettings::class)->default_stream_profile_id;

                if ($defaultId) {
                    $profile = StreamProfile::find($defaultId);
                }
            }

            return app(StreamProfileRuleEvaluator::class)->unwrap($profile, $stream->stream_stats);
        }

        $playlist->loadMissing('vodStreamProfile');
        $profile = $playlist->vodStreamProfile;

        if (! $profile) {
            $defaultId = app(GeneralSettings::class)->default_vod_stream_profile_id;
            if ($defaultId) {
                $profile = StreamProfile::find($defaultId);
            }
        }

        return app(StreamProfileRuleEvaluator::class)->unwrap($profile, $stream->stream_stats);
    }

    private function getDirectStreamUrl(
        Channel|Episode $stream,
        Model $playlist,
        string $type,
        string $authMethod,
        ?int $playlistAuthId,
        array $context = [],
    ): string {
        $format = $context['format'] ?? ($stream instanceof Channel && ! $stream->is_vod
            ? 'ts'
            : ($stream->container_extension ?? 'mkv'));

        return URL::temporarySignedRoute('tv.stream.play', now()->addMinutes(15), [
            'playlistType' => match (true) {
                $playlist instanceof Playlist => 'playlist',
                $playlist instanceof MergedPlaylist => 'merged',
                $playlist instanceof CustomPlaylist => 'custom',
                $playlist instanceof PlaylistAlias => 'alias',
            },
            'playlistId' => $playlist->id,
            'type' => $type,
            'streamId' => $stream->id,
            'format' => $format,
            'auth_method' => $authMethod,
            'auth' => $playlistAuthId,
            ...$context,
        ]);
    }

    private function createTranscodedStreamUrl(
        Channel|Episode $stream,
        Model $playlist,
        ?StreamProfile $profile,
        Request $request,
    ): string {
        $username = $request->attributes->get('tv_username');
        $password = $request->attributes->get('tv_password');

        $playlistAuthId = null;

        $playlistAuth = PlaylistAuth::where('username', $username)
            ->where('password', $password)
            ->where('enabled', true)
            ->whereHas('assignedPlaylist', fn ($query) => $query
                ->where('authenticatable_type', $playlist->getMorphClass())
                ->where('authenticatable_id', $playlist->id))
            ->first();

        if ($playlistAuth && ! $playlistAuth->isExpired()) {
            $playlistAuthId = $playlistAuth->id;
        }

        $service = app(M3uProxyService::class);

        if ($stream instanceof Channel) {
            return $service->getChannelUrl(
                $playlist,
                $stream,
                $request,
                $profile,
                null,
                $playlistAuthId,
            );
        }

        return $service->getEpisodeUrl(
            $playlist,
            $stream,
            $profile,
            null,
            $request,
            $playlistAuthId,
        );
    }

    private function tryCreateTranscodedStream(
        Channel|Episode $stream,
        Model $playlist,
        ?StreamProfile $profile,
        Request $request,
        array $decision,
    ): array {
        try {
            return [
                'mode' => 'transcode',
                'url' => $this->createTranscodedStreamUrl($stream, $playlist, $profile, $request),
                'reason' => $decision['reason'],
                'source' => $decision['source'],
                'output' => $decision['output'],
            ];
        } catch (Throwable $e) {
            Log::warning('TV stream transcode failed', [
                'type' => $stream instanceof Episode ? 'series' : ($stream->is_vod ? 'vod' : 'live'),
                'stream_id' => $stream->id,
                'error_type' => $e::class,
            ]);

            return [
                'mode' => 'unsupported',
                'url' => null,
                'reason' => 'Transcoding unavailable',
                'source' => $decision['source'],
            ];
        }
    }

    private function extractSourceInfo(?array $streamStats): array
    {
        $stats = StreamStatsService::normalize($streamStats ?? []);

        return [
            'video_codec' => $stats['video_codec'] ?? null,
            'audio_codec' => $stats['audio_codec'] ?? null,
            'container' => PlaybackCapabilityService::detectContainer($streamStats),
            'width' => is_numeric($stats['width'] ?? null) ? (int) $stats['width'] : null,
            'height' => is_numeric($stats['height'] ?? null) ? (int) $stats['height'] : null,
            'bitrate_kbps' => is_numeric($stats['bit_rate'] ?? null)
                ? (int) round((int) $stats['bit_rate'] / 1000)
                : null,
            'hdr' => PlaybackCapabilityService::detectHdrState($stats),
        ];
    }

    /**
     * Resolve the playlist and auth scope from Xtream credentials.
     * Returns playlist model, isAdmin flag, and the expected WebSocket channel name.
     *
     * @return array{playlist: Model, isAdmin: bool, channel: string, username: string, password: string, auth_method: string, playlist_auth_id: int|null}
     */
    private function resolveAuth(Request $request): array
    {
        $username = $request->route('username') ?? $request->getUser();
        $password = $request->route('password') ?? $request->getPassword();

        abort_if(! $username || ! $password, 401, 'Missing credentials.');

        $username = (string) $username;
        $password = (string) $password;
        $request->attributes->set('tv_username', $username);
        $request->attributes->set('tv_password', $password);

        $result = PlaylistFacade::authenticate($username, $password);

        abort_if(! $result || ($result[1] ?? 'none') === 'none', 401, 'Invalid credentials.');

        [$playlist, $authMethod] = $result;

        $isAdmin = $authMethod === 'owner_auth' && $playlist->user?->isAdmin();
        $playlistAuth = $authMethod === 'playlist_auth'
            ? PlaylistAuth::query()
                ->where('username', $username)
                ->where('password', $password)
                ->where('enabled', true)
                ->whereHas('assignedPlaylist', fn ($query) => $query
                    ->where('authenticatable_type', $playlist->getMorphClass())
                    ->where('authenticatable_id', $playlist->id))
                ->first()
            : null;
        abort_if($authMethod === 'playlist_auth' && ($playlistAuth === null || $playlistAuth->isExpired()), 401);
        $type = $playlist->getMorphClass();
        $uuid = $playlist->uuid;

        return [
            'playlist' => $playlist,
            'isAdmin' => $isAdmin,
            'channel' => $isAdmin
                ? "private-tv.{$type}-admin.{$uuid}"
                : "private-tv.{$type}.{$uuid}",
            'username' => $username,
            'password' => $password,
            'auth_method' => $authMethod,
            'playlist_auth_id' => $playlistAuth?->id,
        ];
    }
}
