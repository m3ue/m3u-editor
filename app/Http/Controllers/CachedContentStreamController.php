<?php

namespace App\Http\Controllers;

use App\Models\CachedContentFile;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Serve a cached Dynamic Group content file.
 *
 * GET /cached-content/{username}/{password}/{uuid}.{format?}
 *
 * Auth follows the same two-step pattern as
 * `DvrStreamController::resolveUser()` / `XtreamStreamController::findAuthenticatedPlaylistAndStreamModel()`:
 *  - Method 1: PlaylistAuth credentials (returns the playlist it points at)
 *  - Method 2: password = playlist UUID, username = owner's name
 *
 * Once authenticated, the file must be referenced by at least one
 * DynamicGroup belonging to that authenticated playlist (the
 * "ownership" check — CachedContentFile is intentionally a global
 * table with no user_id column, so group-membership is the only way
 * to stop one playlist's credentials from pulling an unrelated
 * playlist's cached file by guessing a uuid).
 *
 * Range serving mirrors `DvrStreamController::stream()`.
 */
class CachedContentStreamController extends Controller
{
    /**
     * Returns [$playlist, $playlistAuth, $isGuestCredential] where:
     *  - $playlist is null when credentials don't resolve,
     *  - $playlistAuth is non-null only when auth resolved via PlaylistAuth, and
     *  - $isGuestCredential mirrors DvrStreamController's same flag.
     *
     * @return array{0: Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias|null, 1: PlaylistAuth|null, 2: bool}
     */
    private function resolvePlaylist(string $username, string $password): array
    {
        // Method 1: PlaylistAuth credentials
        $playlistAuth = PlaylistAuth::where('username', $username)
            ->where('password', $password)
            ->where('enabled', true)
            ->first();

        if ($playlistAuth && ! $playlistAuth->isExpired()) {
            $playlist = $playlistAuth->getAssignedModel();
            if ($playlist) {
                return [$playlist, $playlistAuth, true];
            }
        }

        // Method 2: password = playlist UUID, username = owner's name
        $playlistTypes = [Playlist::class, MergedPlaylist::class, CustomPlaylist::class, PlaylistAlias::class];

        foreach ($playlistTypes as $type) {
            try {
                $playlist = $type::with('user')->where('uuid', $password)->firstOrFail();

                if ($playlist->user && $playlist->user->name === $username) {
                    return [$playlist, null, false];
                }
            } catch (ModelNotFoundException) {
                // Try next type
            }
        }

        return [null, null, false];
    }

    public function stream(Request $request, string $username, string $password, string $uuid)
    {
        [$playlist] = $this->resolvePlaylist($username, $password);
        if (! $playlist) {
            abort(401, 'Invalid credentials');
        }

        // Ownership check is folded into the lookup: the file must be
        // referenced by a DynamicGroup belonging to the authenticated
        // playlist. This matches the DvrStreamController pattern — a
        // non-existent UUID and a uuid that exists but isn't yours both
        // fall through to a 404, so callers can't probe uuid ownership
        // by status code. `cached_content_files` has no user_id column
        // (Phase 1's design — shared global table), so group-membership
        // via whereHas is the only available ownership filter.
        $file = CachedContentFile::where('uuid', $uuid)
            ->whereHas('dynamicGroups', function ($q) use ($playlist) {
                $q->whereHas('playlist', fn ($pq) => $pq->where('id', $playlist->id));
            })
            ->first();

        if (! $file) {
            abort(404, 'Cached file not found');
        }

        if (! $file->hasFilePath()) {
            abort(404, 'Cached file content not available');
        }

        $disk = $file->resolveStorageDisk();

        if (! Storage::disk($disk)->exists($file->file_path)) {
            abort(404, 'Cached file not found on disk');
        }

        $fullPath = Storage::disk($disk)->path($file->file_path);
        $fileSize = filesize($fullPath);
        $mimeType = $file->resolveMimeType();
        $filename = basename($file->file_path);

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
                'Content-Disposition' => "inline; filename=\"{$filename}\"",
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
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
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
}
