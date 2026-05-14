<?php

namespace App\Http\Controllers;

use App\Enums\DvrRecordingStatus;
use App\Models\CustomPlaylist;
use App\Models\DvrRecording;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DvrStreamController extends Controller
{
    public function __construct(protected M3uProxyService $proxy) {}

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
        $user = $this->resolveUser($username, $password);

        if (! $user) {
            abort(401, 'Invalid credentials');
        }

        $recording = DvrRecording::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->first();

        if (! $recording) {
            abort(404, 'Recording not found');
        }

        // In-progress recording — serve through the editor so segment URLs resolve correctly.
        // The proxy's HLS playlist uses relative segment filenames (live000001.ts) which the
        // browser resolves relative to the playlist URL. If we redirect to the proxy directly,
        // segments resolve to /broadcast/{uuid}/live000001.ts instead of
        // /broadcast/{uuid}/segment/live000001.ts. Proxying through the editor lets us rewrite
        // segment URLs to the correct editor route.
        if ($recording->status === DvrRecordingStatus::Recording && $recording->proxy_network_id) {
            return $this->serveLivePlaylist($request, $recording, $username, $password);
        }

        if (! $recording->hasFilePath()) {
            abort(404, 'Recording file not available');
        }

        $setting = $recording->dvrSetting;
        if (! $setting) {
            abort(404, 'DVR setting not found');
        }

        $disk = $setting->storage_disk ?: config('dvr.storage_disk');

        if (! Storage::disk($disk)->exists($recording->file_path)) {
            abort(404, 'Recording file not found on disk');
        }

        $fullPath = Storage::disk($disk)->path($recording->file_path);
        $fileSize = filesize($fullPath);
        $mimeType = $this->resolveMimeType($recording->file_path);

        $range = $request->header('Range');

        if ($range && preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
            $start = (int) $matches[1];
            $end = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : $fileSize - 1;
            $length = $end - $start + 1;

            $headers = [
                'Content-Type' => $mimeType,
                'Content-Length' => $length,
                'Content-Range' => "bytes {$start}-{$end}/{$fileSize}",
                'Accept-Ranges' => 'bytes',
                'Content-Disposition' => 'inline; filename="'.basename($recording->file_path).'"',
            ];

            return response()->stream(function () use ($fullPath, $start, $length) {
                $handle = fopen($fullPath, 'rb');
                if ($handle === false) {
                    return;
                }
                fseek($handle, $start);
                $remaining = $length;

                while (! feof($handle) && $remaining > 0) {
                    $chunkSize = min(8192, $remaining);
                    echo fread($handle, $chunkSize);
                    $remaining -= $chunkSize;
                }

                fclose($handle);
            }, 206, $headers);
        }

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => $fileSize,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline; filename="'.basename($recording->file_path).'"',
        ];

        return response()->stream(function () use ($fullPath) {
            $handle = fopen($fullPath, 'rb');
            if ($handle === false) {
                return;
            }

            while (! feof($handle)) {
                echo fread($handle, 8192);
                flush();
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Serve the live HLS playlist for an in-progress recording.
     *
     * Fetches the playlist from the proxy, rewrites segment URLs to route through the
     * editor, and serves it to the client. This ensures segments resolve correctly
     * (the proxy uses /broadcast/{uuid}/segment/{file}.ts, not relative to the playlist).
     */
    protected function serveLivePlaylist(Request $request, DvrRecording $recording, string $username, string $password): Response
    {
        $networkId = $recording->proxy_network_id;
        // Use the API base URL for the internal fetch (editor→proxy within Docker).
        // The public URL (getDvrBroadcastLiveUrl) is for clients outside the network.
        $playlistUrl = $this->proxy->getDvrBroadcastLiveApiUrl($networkId);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-API-Token' => $this->proxy->getApiToken(),
                ])
                ->get($playlistUrl);

            if (! $response->successful()) {
                abort($response->status(), 'Broadcast not available');
            }

            $playlist = $response->body();

            // Rewrite segment URLs to route through the editor.
            // Proxy outputs: live000001.ts
            // We rewrite to: /dvr-hls/{uuid}/live000001.ts
            $baseUrl = url("/dvr-hls/{$recording->uuid}");
            $playlist = preg_replace(
                '/^(live\d+\.ts)\r?$/m',
                $baseUrl.'/$1',
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
     * Serve the HLS playlist for an in-progress DVR recording (no-auth route).
     *
     * The recording UUID acts as the security token (hard to guess).
     */
    public function hlsPlaylist(Request $request, string $uuid): Response
    {
        $recording = DvrRecording::where('uuid', $uuid)
            ->where('status', DvrRecordingStatus::Recording)
            ->whereNotNull('proxy_network_id')
            ->first();

        if (! $recording) {
            abort(404, 'Recording not found or not in progress');
        }

        return $this->serveLivePlaylist($request, $recording, '', '');
    }

    /**
     * Serve an HLS segment for an in-progress DVR recording (no-auth route).
     *
     * Proxies the segment from the proxy through the editor so external players don't
     * need direct access to the proxy's public URL (which may resolve to a different
     * domain/environment than the editor).
     */
    public function hlsSegment(Request $request, string $uuid, string $segment): Response
    {
        $recording = DvrRecording::where('uuid', $uuid)
            ->where('status', DvrRecordingStatus::Recording)
            ->whereNotNull('proxy_network_id')
            ->first();

        if (! $recording) {
            abort(404, 'Recording not found or not in progress');
        }

        $segmentUrl = $this->proxy->getDvrBroadcastSegmentApiUrl($recording->proxy_network_id, $segment);

        try {
            $response = Http::timeout(15)
                ->withOptions(['stream' => true])
                ->withHeaders([
                    'X-API-Token' => $this->proxy->getApiToken(),
                ])
                ->get($segmentUrl);

            if (! $response->successful()) {
                abort($response->status(), 'Segment not available');
            }

            return response()->stream(function () use ($response) {
                $stream = $response->toPsrResponse()->getBody();
                while (! $stream->eof()) {
                    echo $stream->read(8192);
                    flush();
                }
            }, 200, [
                'Content-Type' => 'video/MP2T',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Access-Control-Allow-Origin' => '*',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to proxy DVR broadcast segment', [
                'recording_id' => $recording->id,
                'segment' => $segment,
                'error' => $e->getMessage(),
            ]);

            abort(503, 'Segment not available');
        }
    }

    /**
     * Resolve a User from credentials using the same two-step auth as XtreamStreamController:
     * 1. PlaylistAuth username/password lookup
     * 2. Fallback: username = user's name, password = any playlist UUID owned by that user
     *
     * Returns the owning User model or null on failure.
     */
    private function resolveUser(string $username, string $password): ?User
    {
        // Method 1: PlaylistAuth credentials
        $playlistAuth = PlaylistAuth::where('username', $username)
            ->where('password', $password)
            ->where('enabled', true)
            ->first();

        if ($playlistAuth && ! $playlistAuth->isExpired()) {
            $playlist = $playlistAuth->getAssignedModel();

            return $playlist?->user;
        }

        // Method 2: password = playlist UUID, username = owner's name
        $playlistTypes = [Playlist::class, MergedPlaylist::class, CustomPlaylist::class, PlaylistAlias::class];

        foreach ($playlistTypes as $type) {
            try {
                $playlist = $type::with('user')->where('uuid', $password)->firstOrFail();

                if ($playlist->user && $playlist->user->name === $username) {
                    return $playlist->user;
                }
            } catch (ModelNotFoundException) {
                // Try next type
            }
        }

        return null;
    }

    /**
     * Resolve MIME type from the recording file extension.
     */
    private function resolveMimeType(string $filePath): string
    {
        return match (strtolower(pathinfo($filePath, PATHINFO_EXTENSION))) {
            'mp4' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            default => 'video/mp2t',
        };
    }
}
