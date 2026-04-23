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

        // In-progress recording — redirect to the live HLS stream on the proxy.
        // Clients (VLC, Infuse, etc.) will play the live HLS just as they would
        // play the completed file, and will naturally see new segments as they arrive.
        if ($recording->status === DvrRecordingStatus::Recording && $recording->proxy_network_id) {
            $liveUrl = $this->proxy->getDvrBroadcastLiveUrl($recording->proxy_network_id);

            return redirect($liveUrl);
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
