<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PluginApiRequestException;
use App\Http\Controllers\Controller;
use App\Plugins\PluginManager;
use App\Services\XtreamLoginAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

/**
 * POST /api/plugins/{plugin}/{endpoint}: calls a trusted plugin's manifest-declared
 * endpoint on behalf of an Xtream login.
 *
 * Credentials come from an HTTP Basic header or the JSON body, never the URL, and
 * resolve through the same authenticator as /player_api.php. The plugin receives the
 * playlist the login unlocks, never the credentials.
 */
class PluginApiController extends Controller
{
    public const ENVELOPE_VERSION = 1;

    public function __invoke(
        Request $request,
        string $pluginId,
        string $endpoint,
        XtreamLoginAuthenticator $authenticator,
        PluginManager $plugins,
    ): JsonResponse {
        $maxRequestBytes = (int) config('plugins.api.max_request_bytes', 131072);
        if (strlen($request->getContent()) > $maxRequestBytes || (int) $request->header('Content-Length', '0') > $maxRequestBytes) {
            return $this->error(413, 'request_too_large');
        }

        if (! $request->isJson()) {
            return $this->error(415, 'json_required');
        }

        $body = $request->json()->all();
        [$username, $password] = $this->credentials($request, $body);
        $login = $authenticator->login($username, $password);
        if ($login === null) {
            return $this->error(401, 'unauthorized')->header('WWW-Authenticate', 'Basic realm="plugins"');
        }

        $payload = $body['payload'] ?? [];
        if (! is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            return $this->error(422, 'invalid_payload', ['payload']);
        }

        try {
            $data = $plugins->handleApiRequest($pluginId, $endpoint, $payload, $login['playlist'], $login['playlist_auth']);
        } catch (PluginApiRequestException $exception) {
            return $this->error($exception->status, $exception->errorCode, $exception->fields);
        }

        try {
            $response = response()->json([
                'api_version' => self::ENVELOPE_VERSION,
                'plugin_id' => $pluginId,
                'endpoint' => $endpoint,
                'data' => $data,
            ], options: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error(500, 'plugin_request_failed');
        }

        if (strlen((string) $response->getContent()) > (int) config('plugins.api.max_response_bytes', 524288)) {
            return $this->error(413, 'response_too_large');
        }

        return $response;
    }

    /**
     * Basic auth wins over body fields. The query string is never read, so
     * credentials pasted into a URL (and its access logs) do not authenticate.
     *
     * @param  array<string, mixed>  $body
     * @return array{0: mixed, 1: mixed}
     */
    private function credentials(Request $request, array $body): array
    {
        if ($request->getUser() !== null) {
            return [$request->getUser(), $request->getPassword()];
        }

        return [$body['username'] ?? null, $body['password'] ?? null];
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function error(int $status, string $code, array $fields = []): JsonResponse
    {
        return response()->json(array_filter([
            'error' => $code,
            'fields' => $fields,
        ]), $status);
    }
}
