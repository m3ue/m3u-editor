<?php

namespace App\Plugins\Support;

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\Plugin;
use App\Support\PrivateNetworkGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Server-built context for one plugin API request.
 *
 * It carries identifiers only: no request object, credentials, tokens or
 * playlist UUIDs (a UUID can itself be a default-login password).
 */
final class PluginApiRequestContext
{
    private const MAX_TIMEOUT_SECONDS = 15;

    /**
     * @param  object{id: int, type: class-string, user_id: int}  $playlist  The playlist the login unlocks.
     * @param  object{id: int, user_id: int}|null  $playlistAuth  Set only for PlaylistAuth logins.
     * @param  array<string, mixed>  $settings  The plugin's resolved settings. Never echo these back.
     */
    public function __construct(
        public readonly string $pluginId,
        public readonly string $endpoint,
        public readonly object $playlist,
        public readonly ?object $playlistAuth,
        public readonly array $settings,
        private readonly bool $networkEgressAllowed,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function make(
        Plugin $plugin,
        string $endpoint,
        Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias $playlist,
        ?PlaylistAuth $playlistAuth,
        array $settings,
    ): self {
        return new self(
            pluginId: (string) $plugin->plugin_id,
            endpoint: $endpoint,
            playlist: (object) [
                'id' => $playlist->id,
                'type' => $playlist::class,
                'user_id' => $playlist->user_id,
            ],
            playlistAuth: $playlistAuth ? (object) [
                'id' => $playlistAuth->id,
                'user_id' => $playlistAuth->user_id,
            ] : null,
            settings: $settings,
            networkEgressAllowed: in_array('network_egress', $plugin->permissions ?? [], true),
        );
    }

    /**
     * GET a URL with the host's SSRF guard: http(s) only, no private, loopback,
     * link-local or reserved destinations, every redirect hop re-checked and the
     * resolved IP pinned against DNS rebinding.
     *
     * Use this for any URL that a caller's payload can influence.
     *
     * @throws RuntimeException When the plugin did not declare [network_egress].
     */
    public function safeGet(string $url, int $timeoutSeconds = 10): Response
    {
        if (! $this->networkEgressAllowed) {
            throw new RuntimeException('Plugin must declare the [network_egress] permission to make outbound requests.');
        }

        $timeout = max(1, min($timeoutSeconds, self::MAX_TIMEOUT_SECONDS));

        return PrivateNetworkGuard::get($url, fn (): PendingRequest => Http::timeout($timeout));
    }
}
