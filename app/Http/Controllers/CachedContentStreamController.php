<?php

namespace App\Http\Controllers;

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentManagedBy;
use App\Http\Controllers\Concerns\StreamLocalFile;
use App\Models\CachedContentActivityLease;
use App\Models\CachedContentFile;
use App\Models\Playlist;
use App\Services\CachedContentActivityService;
use App\Services\PlaylistCredentialResolver;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve a cached VOD movie or series episode.
 *
 * GET /cached-content/{username}/{password}/{uuid}.{format?}
 *
 * The route is public at the middleware level, so authorization happens
 * here: the credentials must resolve to a plain Playlist (the only type
 * that owns cached files), and the requested file must belong to that
 * playlist or be shared with it by another of the same user's playlists.
 * Anything else is a 404 so the uuid's existence isn't leaked.
 */
class CachedContentStreamController extends Controller
{
    private ?CachedContentActivityLease $readerLease = null;

    public function __construct(
        protected PlaylistCredentialResolver $resolver,
        protected CachedContentActivityService $activity,
    ) {}

    public function stream(Request $request, string $username, string $password, string $uuid): StreamedResponse
    {
        $playlist = $this->resolver->resolve($username, $password);
        if (! $playlist) {
            abort(401, 'Invalid credentials');
        }

        if (! (app(GeneralSettings::class)->enable_cache ?? false) || ! $playlist instanceof Playlist) {
            abort(404, 'Cached file not found');
        }

        $file = CachedContentFile::query()
            ->where('uuid', $uuid)
            ->where('status', CachedContentFileStatus::Completed->value)
            ->where(function (Builder $q) use ($playlist): void {
                $q->where('playlist_id', $playlist->id)
                    ->orWhere(fn (Builder $shared) => $shared->sharedWithPlaylist($playlist));
            })
            ->first();

        if (! $file || ! $file->hasFilePath()) {
            abort(404, 'Cached file not found');
        }

        // Managed-publishing files need a reader lease BEFORE the file is
        // stat'd or opened so deletion races see the active reader under the
        // same lock. The lease is acquired here and released in onFinish.
        if ($file->managed_by === CachedContentManagedBy::ManagedPublishing) {
            $this->readerLease = $this->activity->acquireReaderForFile(
                $playlist->user,
                null,
                (string) Str::uuid(),
                $file,
            );
        }

        $disk = Storage::disk($file->resolveStorageDisk());

        // size() throws on a missing file (some drivers return false); either
        // way the file is gone.
        try {
            $fileSize = (int) $disk->size($file->file_path);
        } catch (\Throwable) {
            $this->releaseReaderLease();
            abort(404, 'Cached file not found on disk');
        }
        if ($fileSize < 1) {
            $this->releaseReaderLease();
            abort(404, 'Cached file not found on disk');
        }

        return StreamLocalFile::serve(
            fullPath: $disk->path($file->file_path),
            fileSize: $fileSize,
            mimeType: $file->resolveMimeType(),
            filename: basename($file->file_path),
            range: $request->header('Range'),
            onProgress: fn () => $this->readerLease && $this->activity->renewReader($this->readerLease),
            onFinish: fn () => $this->releaseReaderLease(),
        );
    }

    private function releaseReaderLease(): void
    {
        if ($this->readerLease !== null) {
            try {
                $this->activity->releaseReader($this->readerLease);
            } catch (\Throwable) {
                // Best-effort release; do not break the HTTP response.
            }
            $this->readerLease = null;
        }
    }
}
