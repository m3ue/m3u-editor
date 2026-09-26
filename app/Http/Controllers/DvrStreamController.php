<?php

namespace App\Http\Controllers;

use App\Enums\DvrRecordingStatus;
use App\Http\Controllers\Concerns\StreamLocalFile;
use App\Models\DvrRecording;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\DvrCapabilityGate;
use App\Services\M3uProxyService;
use App\Services\PlaylistCredentialResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DvrStreamController extends Controller
{
    public function __construct(protected M3uProxyService $proxy, protected PlaylistCredentialResolver $resolver) {}

    /**
     * Stream a DVR recording.
     *
     * GET /dvr/{username}/{password}/{uuid}.{format?}
     *
     * Authentication mirrors the Xtream stream pattern:
     * - Method 1: PlaylistAuth credentials
     * - Method 2: username = playlist owner's name, password = playlist UUID
     *
     * Once authenticated, the recording must belong to that user.
     * Supports HTTP range requests for seeking.
     */
    public function stream(Request $request, string $username, string $password, string $uuid): Response|StreamedResponse|RedirectResponse
    {
        [$user, $playlistAuth, $isGuestCredential] = $this->resolveUser($username, $password);

        if (! $user) {
            abort(401, 'Invalid credentials');
        }

        $recording = DvrRecording::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->when($isGuestCredential, fn ($q) => $q->where('playlist_auth_id', $playlistAuth->id))
            ->first();

        if (! $recording || ! DvrCapabilityGate::granted($recording->dvrSetting, $playlistAuth, $isGuestCredential)) {
            abort(404, 'Recording not found');
        }

        // In-progress recording — serve through the editor so segment URLs resolve correctly.
        // The proxy's HLS playlist uses relative segment filenames (live000001.ts) which the
        // browser resolves relative to the playlist URL. If we redirect to the proxy directly,
        // segments resolve to /broadcast/{uuid}/live000001.ts instead of
        // /broadcast/{uuid}/segment/live000001.ts. Proxying through the editor lets us rewrite
        // segment URLs to the correct editor route.
        if ($recording->status === DvrRecordingStatus::Recording && $recording->proxy_network_id) {
            return $this->serveLivePlaylist($request, $recording);
        }

        if (! $recording->hasFilePath()) {
            abort(404, 'Recording file not available');
        }

        $setting = $recording->dvrSetting;
        if (! $setting) {
            abort(404, 'DVR setting not found');
        }

        $disk = $recording->resolveStorageDisk();

        if (! Storage::disk($disk)->exists($recording->file_path)) {
            abort(404, 'Recording file not found on disk');
        }

        $fullPath = Storage::disk($disk)->path($recording->file_path);

        // Storage::size() throws on a missing file; some drivers return
        // false. Both are unacceptable for the int $fileSize contract
        // that StreamLocalFile::serve() enforces.
        try {
            $fileSize = Storage::disk($disk)->size($recording->file_path);
        } catch (\Throwable) {
            abort(404, 'Recording file not found on disk');
        }
        if ($fileSize === false || $fileSize < 1) {
            abort(404, 'Recording file not found on disk');
        }
        $fileSize = (int) $fileSize;

        return StreamLocalFile::serve(
            fullPath: $fullPath,
            fileSize: $fileSize,
            mimeType: $recording->resolveMimeType(),
            filename: basename($recording->file_path),
            range: $request->header('Range'),
        );
    }

    /**
     * Serve the live HLS playlist for an in-progress recording.
     *
     * Fetches the playlist from the proxy and rewrites the relative segment filenames
     * to absolute URLs pointing directly at the proxy's public segment endpoint.
     * This means only the small playlist file (~1 KB) passes through the editor on
     * each reload; all TS segment traffic goes straight from the proxy to the client.
     */
    protected function serveLivePlaylist(Request $request, DvrRecording $recording): Response
    {
        $networkId = $recording->proxy_network_id;
        // Use the internal API URL for the editor→proxy fetch (within Docker).
        $playlistUrl = $this->proxy->getDvrBroadcastLiveApiUrl($networkId);

        try {
            $httpRequest = Http::timeout(10);

            if ($token = $this->proxy->getApiToken()) {
                $httpRequest = $httpRequest->withHeaders(['X-API-Token' => $token]);
            }

            $response = $httpRequest->get($playlistUrl);

            if (! $response->successful()) {
                abort($response->status(), 'Broadcast not available');
            }

            $playlist = $response->body();

            // Rewrite relative segment names (e.g. live000001.ts) to absolute public
            // proxy URLs (e.g. https://proxy.example.com/broadcast/{id}/segment/live000001.ts).
            // Clients fetch segments directly from the proxy — the editor is not in the
            // segment path at all, eliminating double-download overhead.
            $segmentBase = rtrim($this->proxy->getPublicUrl(), '/').'/broadcast/'.rawurlencode($networkId).'/segment/';
            $playlist = preg_replace(
                '/^(live\d+\.ts)\r?$/m',
                $segmentBase.'$1',
                $playlist
            );

            return response($playlist, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Access-Control-Allow-Origin' => '*',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch DVR broadcast playlist', [
                'recording_id' => $recording->id,
                'proxy_network_id' => $networkId,
                'error' => $e->getMessage(),
            ]);

            abort(503, 'Broadcast not available');
        }
    }

    /**
     * Serve the HLS playlist for an in-progress DVR recording.
     *
     * Authentication mirrors stream(): PlaylistAuth credentials or
     * username = owner's name / password = playlist UUID (Method 2).
     * Segment traffic never passes through the editor — only this playlist does.
     */
    public function hlsPlaylist(Request $request, string $username, string $password, string $uuid): Response
    {
        [$user, $playlistAuth, $isGuestCredential] = $this->resolveUser($username, $password);

        if (! $user) {
            abort(401, 'Invalid credentials');
        }

        $recording = DvrRecording::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->when($isGuestCredential, fn ($q) => $q->where('playlist_auth_id', $playlistAuth->id))
            ->where('status', DvrRecordingStatus::Recording)
            ->whereNotNull('proxy_network_id')
            ->first();

        if (! $recording || ! DvrCapabilityGate::granted($recording->dvrSetting, $playlistAuth, $isGuestCredential)) {
            abort(404, 'Recording not found or not in progress');
        }

        return $this->serveLivePlaylist($request, $recording);
    }

    /**
     * Return the comskip EDL segments for a completed recording as JSON.
     *
     * GET /dvr/{username}/{password}/{uuid}/edl
     *
     * Returns an array of commercial segments: [{"start": 1.0, "end": 120.0}, ...]
     * Returns an empty array when no EDL file exists or comskip was not run.
     */
    public function edl(string $username, string $password, string $uuid): JsonResponse
    {
        [$user, $playlistAuth, $isGuestCredential] = $this->resolveUser($username, $password);

        if (! $user) {
            abort(401, 'Invalid credentials');
        }

        $recording = DvrRecording::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->when($isGuestCredential, fn ($q) => $q->where('playlist_auth_id', $playlistAuth->id))
            ->first();

        if (! $recording || ! DvrCapabilityGate::granted($recording->dvrSetting, $playlistAuth, $isGuestCredential)) {
            abort(404, 'Recording not found');
        }

        if (! $recording->hasFilePath()) {
            return response()->json([]);
        }

        $disk = $recording->resolveStorageDisk();

        $edlPath = pathinfo($recording->file_path, PATHINFO_DIRNAME)
            .'/'.pathinfo($recording->file_path, PATHINFO_FILENAME).'.edl';

        if (! Storage::disk($disk)->exists($edlPath)) {
            return response()->json([]);
        }

        $segments = [];

        foreach (explode("\n", trim(Storage::disk($disk)->get($edlPath) ?? '')) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 3) {
                continue;
            }

            // EDL format: start_time end_time type (type 0 = commercial cut)
            if ((int) $parts[2] === 0) {
                $segments[] = ['start' => (float) $parts[0], 'end' => (float) $parts[1]];
            }
        }

        return response()->json($segments);
    }

    /**
     * Resolve a User from credentials using the same two-step auth as XtreamStreamController:
     * 1. PlaylistAuth username/password lookup
     * 2. Fallback: username = user's name, password = any playlist UUID owned by that user
     *
     * The actual credential resolution is delegated to the shared
     * `PlaylistCredentialResolver` service. This wrapper remains here
     * because the DVR query then needs to additionally filter by the
     * resolved PlaylistAuth (Method 1) so one guest credential cannot
     * reach another guest's recordings just because they share an
     * owning user (mirrors the playlist_auth_id scoping
     * XtreamApiController applies to every other DVR query).
     *
     * @return array{0: ?User, 1: ?PlaylistAuth, 2: bool} The owning user, the resolved guest
     *                                                    credential (null for owner auth), and
     *                                                    whether auth resolved as a guest credential.
     */
    private function resolveUser(string $username, string $password): array
    {
        // Method 1: PlaylistAuth credentials
        $playlistAuth = $this->resolver->resolveAuth($username, $password);

        if ($playlistAuth) {
            $playlist = $playlistAuth->getAssignedModel();

            return [$playlist?->user, $playlistAuth, true];
        }

        // Method 2: password = playlist UUID, username = owner's name
        $playlist = $this->resolver->resolveByUuid($password, $username);
        if ($playlist) {
            return [$playlist->user, null, false];
        }

        return [null, null, false];
    }
}
