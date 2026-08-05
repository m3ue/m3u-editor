<?php

namespace App\Http\Controllers\Api;

use App\Facades\PlaylistFacade;
use App\Http\Controllers\Controller;
use App\Models\PlaylistAlias;
use App\Models\PushDeviceToken;
use App\Models\TvNotification;
use App\Services\M3uProxyService;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TvApiController extends Controller
{
    /**
     * GET /api/tv/{username}/{password}/notifications
     *
     * Returns unread TV notifications for the authenticated playlist.
     * Admin-scope sessions (owner_auth + isAdmin) also see admin_only notifications.
     * Owner-auth sessions are intentionally limited to global notifications; admin
     * status only expands that global scope to include admin_only rows.
     * Pass optional `channels[]` query param to filter by notification channel.
     * Also returns Reverb connection config so the TV app can subscribe.
     */
    public function notifications(Request $request): JsonResponse
    {
        $auth = $this->resolveAuth($request);
        $playlist = $auth['playlist'];

        $query = TvNotification::query();
        $this->scopeToNotifiable($query, $playlist);
        $query->when(! $auth['isAdmin'], fn (Builder $query) => $query->where('admin_only', false));

        if ($auth['playlistAuthId'] === null) {
            $query->whereNull('playlist_auth_id')
                ->whereNull('read_at');
        } else {
            $query->where(function (Builder $query) use ($auth): void {
                $query->where(function (Builder $query) use ($auth): void {
                    $query->where('playlist_auth_id', $auth['playlistAuthId'])
                        ->whereNull('read_at');
                })->orWhere(function (Builder $query) use ($auth): void {
                    $query->whereNull('playlist_auth_id')
                        ->whereDoesntHave('credentialReads', fn (Builder $query) => $query
                            ->where('playlist_auth_id', $auth['playlistAuthId']));
                });
            });
        }

        $query->latest()->limit(50);

        if ($request->filled('channels')) {
            $query->whereIn('channel', (array) $request->input('channels'));
        }

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
                'host' => $request->getHost(),
                'port' => (int) $request->getPort(),
                'scheme' => $request->isSecure() ? 'wss' : 'ws',
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
     * Owner-auth sessions intentionally may mark only global notifications; admin
     * status only adds permission to mark admin_only global rows.
     */
    public function markRead(Request $request): JsonResponse
    {
        $auth = $this->resolveAuth($request);
        $playlist = $auth['playlist'];
        $id = $request->route('id');

        $query = TvNotification::where('id', $id);
        $this->scopeToNotifiable($query, $playlist);

        $notification = $query
            ->when(! $auth['isAdmin'], fn (Builder $query) => $query->where('admin_only', false))
            ->when(
                $auth['playlistAuthId'] === null,
                fn (Builder $query) => $query->whereNull('playlist_auth_id'),
                fn (Builder $query) => $query->where(function (Builder $query) use ($auth): void {
                    $query->where('playlist_auth_id', $auth['playlistAuthId'])
                        ->orWhereNull('playlist_auth_id');
                }),
            )
            ->firstOrFail();

        if ($auth['playlistAuthId'] !== null && $notification->playlist_auth_id === null) {
            $notification->credentialReads()->firstOrCreate(
                ['playlist_auth_id' => $auth['playlistAuthId']],
                ['read_at' => now()],
            );
        } else {
            $notification->update(['read_at' => now()]);
        }

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

    /**
     * POST /api/tv/{username}/{password}/push/subscribe
     *
     * Registers (or refreshes) a mobile device's FCM push token against the
     * authenticated playlist, so the push relay job can reach it. Mobile only
     * (see PushNotificationService in the Flutter client) - TV builds don't call this.
     */
    public function registerPushToken(Request $request): JsonResponse
    {
        $auth = $this->resolveAuth($request);
        $playlist = $auth['playlist'];

        $data = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['required', 'string', 'in:ios,android'],
        ]);

        DB::transaction(function () use ($auth, $data, $playlist): void {
            $device = PushDeviceToken::where('token', $data['token'])
                ->lockForUpdate()
                ->first();

            if ($device === null) {
                PushDeviceToken::create([
                    'notifiable_type' => $playlist->getMorphClass(),
                    'notifiable_id' => $playlist->id,
                    'token' => $data['token'],
                    'platform' => $data['platform'],
                    'last_seen_at' => now(),
                    'playlist_auth_id' => $auth['playlistAuthId'],
                ]);

                return;
            }

            $device->update([
                'notifiable_type' => $playlist->getMorphClass(),
                'notifiable_id' => $playlist->id,
                'platform' => $data['platform'],
                'last_seen_at' => now(),
                'playlist_auth_id' => $auth['playlistAuthId'],
            ]);
        });

        return response()->json(['ok' => true]);
    }

    public function unregisterPushToken(Request $request): JsonResponse
    {
        $auth = $this->resolveAuth($request);
        $playlist = $auth['playlist'];
        $data = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
        ]);

        PushDeviceToken::query()
            ->where('notifiable_type', $playlist->getMorphClass())
            ->where('notifiable_id', $playlist->id)
            ->where('playlist_auth_id', $auth['playlistAuthId'])
            ->where('token', $data['token'])
            ->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/tv/{username}/{password}/player-stream/stop
     *
     * Releases this client's registration on a pooled proxy stream (force: false),
     * so other viewers of the same stream stay connected. Scoped to the authenticated
     * playlist/credential — the requested channel or episode must belong to it, so one
     * viewer cannot stop another playlist's stream. Idempotent: always returns 204,
     * including when the client is already gone or the ID is out of scope, so a caller
     * can't use the response to probe whether an ID belongs to someone else.
     */
    public function stopPlayerStream(Request $request): JsonResponse
    {
        $auth = $this->resolveAuth($request);
        $playlist = $auth['playlist'];

        $data = $request->validate([
            'type' => ['required', 'string', 'in:live,vod,catchup,series'],
            'stream_id' => ['required_unless:type,series', 'integer'],
            'episode_id' => ['required_if:type,series', 'integer'],
            'client_id' => ['required', 'string', 'max:128', 'regex:/^[\w-]+$/'],
        ]);

        $isSeries = $data['type'] === 'series';
        $field = $isSeries ? 'episode_id' : 'channel_id';
        $id = $isSeries ? $data['episode_id'] : $data['stream_id'];

        $belongsToPlaylist = $isSeries
            ? (method_exists($playlist, 'episodes') && $playlist->episodes()->whereKey($id)->exists())
            : (method_exists($playlist, 'channels') && $playlist->channels()->whereKey($id)->exists());

        if ($belongsToPlaylist) {
            M3uProxyService::stopStreamSafely($field, (string) $id, $data['client_id'], 'Failed to stop TV player stream');
        }

        return response()->json(null, 204);
    }

    /**
     * A credential assigned to a PlaylistAlias only ever subscribes to the alias's own
     * channel, but DVR/global notifications are raised against the alias's *effective*
     * playlist. So an alias guest must also see (and be able to mark read) global rows
     * recorded against that effective playlist — never rows targeted at another credential.
     */
    private function scopeToNotifiable(Builder $query, Model $playlist): Builder
    {
        $effectivePlaylist = $playlist instanceof PlaylistAlias ? $playlist->getEffectivePlaylist() : null;

        return $query->where(function (Builder $query) use ($playlist, $effectivePlaylist): void {
            $query->where(function (Builder $query) use ($playlist): void {
                $query->where('notifiable_type', $playlist->getMorphClass())
                    ->where('notifiable_id', $playlist->id);
            });

            if ($effectivePlaylist) {
                $query->orWhere(function (Builder $query) use ($effectivePlaylist): void {
                    $query->where('notifiable_type', $effectivePlaylist->getMorphClass())
                        ->where('notifiable_id', $effectivePlaylist->id)
                        ->whereNull('playlist_auth_id')
                        ->where('admin_only', false);
                });
            }
        });
    }

    /**
     * Resolve the playlist and auth scope from Xtream credentials in the URL path.
     * Returns playlist model, isAdmin flag, and the expected WebSocket channel name.
     *
     * @return array{playlist: Model, isAdmin: bool, playlistAuthId: ?int, channel: string}
     */
    private function resolveAuth(Request $request): array
    {
        $username = $request->route('username');
        $password = $request->route('password');

        abort_if(! $username || ! $password, 401, 'Missing credentials.');

        $result = PlaylistFacade::authenticate($username, $password);

        abort_if(! $result || ($result[1] ?? 'none') === 'none', 401, 'Invalid credentials.');

        [$playlist, $authMethod] = $result;

        $isAdmin = $authMethod === 'owner_auth' && $playlist->user?->isAdmin();
        $type = $playlist->getMorphClass();
        $uuid = $playlist->uuid;

        return [
            'playlist' => $playlist,
            'isAdmin' => $isAdmin,
            'playlistAuthId' => $result[4] ?? null,
            'channel' => $this->channelName($type, $uuid, $isAdmin, $result[4] ?? null),
        ];
    }

    private function channelName(string $type, string $uuid, bool $isAdmin, ?int $playlistAuthId): string
    {
        if ($isAdmin) {
            return "private-tv.{$type}-admin.{$uuid}";
        }

        return "private-tv.{$type}.{$uuid}".($playlistAuthId === null ? '' : ".{$playlistAuthId}");
    }
}
