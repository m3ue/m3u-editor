<?php

namespace AppLocalPlugins\ApiDemo;

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Plugins\Contracts\ApiEndpointPluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginApiRequestContext;
use App\Plugins\Support\PluginExecutionContext;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reference implementation of the [api_endpoints] capability.
 *
 * Patterns worth copying:
 *  - resolve the playlist from the server-built context only, never from the payload;
 *  - allowlist the four playlist model classes and scope by the context's user_id;
 *  - stay read-only;
 *  - fetch caller-influenced URLs only through $context->safeGet().
 */
class Plugin implements ApiEndpointPluginInterface, PluginInterface
{
    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return PluginActionResult::failure('API Demo has no manual actions.');
    }

    public function handleApiRequest(string $endpoint, array $payload, PluginApiRequestContext $context): array
    {
        return match ($endpoint) {
            'playlist-summary' => $this->playlistSummary($context),
            'echo' => $this->echo($payload, $context),
            'url-status' => $this->urlStatus($payload, $context),
            default => throw new RuntimeException("Unknown endpoint [{$endpoint}]."),
        };
    }

    private function playlistSummary(PluginApiRequestContext $context): array
    {
        $class = match ($context->playlist->type) {
            Playlist::class => Playlist::class,
            CustomPlaylist::class => CustomPlaylist::class,
            MergedPlaylist::class => MergedPlaylist::class,
            PlaylistAlias::class => PlaylistAlias::class,
            default => throw new RuntimeException('Unsupported playlist type.'),
        };

        $playlist = $class::query()
            ->where('user_id', $context->playlist->user_id)
            ->findOrFail($context->playlist->id);

        return [
            'name' => $playlist->name,
            'type' => class_basename($class),
            'via_playlist_auth' => $context->playlistAuth !== null,
        ];
    }

    private function echo(array $payload, PluginApiRequestContext $context): array
    {
        $message = (string) $payload['message'];
        $greeting = (string) ($context->settings['greeting'] ?? 'Hello');

        return [
            'message' => ($payload['shout'] ?? false) ? mb_strtoupper($message) : $message,
            'greeting' => $greeting,
        ];
    }

    private function urlStatus(array $payload, PluginApiRequestContext $context): array
    {
        try {
            $response = $context->safeGet((string) $payload['url'], timeoutSeconds: 5);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['url' => 'This URL is not allowed.']);
        }

        return ['status' => $response->status()];
    }
}
