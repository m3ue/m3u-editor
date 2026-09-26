<?php

namespace App\Models;

use App\Enums\CachedContentFileStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A locally cached copy of one VOD channel or series episode.
 *
 * Each row belongs to exactly one source item (`cacheable`: a Channel or
 * an Episode). `content_fingerprint` is the item's TMDB/TVDB identity and
 * is only used to find a copy that another of the same user's playlists
 * shares (see `scopeSharedWithPlaylist()`).
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property int|null $playlist_id
 * @property string $cacheable_type
 * @property int $cacheable_id
 * @property string $content_type
 * @property string|null $tmdb_id
 * @property string|null $tvdb_id
 * @property int|null $season_number
 * @property int|null $episode_number
 * @property string|null $quality
 * @property string $content_fingerprint
 * @property string|null $title
 * @property string|null $disk
 * @property string|null $file_path
 * @property int|null $file_size_bytes
 * @property int|null $bytes_downloaded
 * @property int|null $bytes_expected
 * @property int|null $bytes_per_second
 * @property Carbon|null $last_progress_at
 * @property CachedContentFileStatus $status
 * @property Carbon|null $last_verified_at
 * @property Carbon|null $last_failed_at
 * @property string|null $last_error_message
 * @property int $failure_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static> ownedBy(int $userId)
 * @method static Builder<static> sharedWithPlaylist(Playlist|int $playlist)
 * @method static Builder<static> servableFor(Channel|Episode $item)
 */
class CachedContentFile extends Model
{
    use HasFactory;

    /**
     * Storage disk every cached file is written to (config/filesystems.php).
     */
    public const DISK = 'cache';

    protected $fillable = [
        'user_id',
        'playlist_id',
        'cacheable_type',
        'cacheable_id',
        'content_type',
        'tmdb_id',
        'tvdb_id',
        'season_number',
        'episode_number',
        'quality',
        'content_fingerprint',
        'title',
        'disk',
        'file_path',
        'file_size_bytes',
        'bytes_downloaded',
        'bytes_expected',
        'bytes_per_second',
        'last_progress_at',
        'status',
        'last_verified_at',
        'last_failed_at',
        'last_error_message',
        'failure_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CachedContentFileStatus::class,
            'failure_count' => 'integer',
            'last_verified_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'last_progress_at' => 'datetime',
            'file_size_bytes' => 'integer',
            'bytes_downloaded' => 'integer',
            'bytes_expected' => 'integer',
            'bytes_per_second' => 'integer',
            'season_number' => 'integer',
            'episode_number' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (CachedContentFile $file): void {
            if (empty($file->uuid)) {
                $file->uuid = (string) Str::uuid();
            }

            if (empty($file->content_fingerprint)) {
                $file->content_fingerprint = static::fingerprintFor($file->getAttributes());
            }

            if (static::normalizeContentType($file->content_type) === '') {
                throw new \InvalidArgumentException('CachedContentFile requires non-empty content_type.');
            }
        });
    }

    /**
     * Build a deterministic content fingerprint from identity parts.
     *
     * Format: content_type:tmdb_id:tvdb_id:season_number:episode_number:quality[:local_key]
     * - content_type: lowercased+trimmed, REQUIRED (throws if empty)
     * - quality: lowercased+trimmed
     * - tmdb_id/tvdb_id: string-coerced
     * - season_number/episode_number: (int) cast then stringified
     * - local_key: appended only when both tmdb_id and tvdb_id are empty, so
     *   unmatched items never look like the same content (and so can never
     *   be shared across playlists).
     *
     * @param  array{content_type: string, tmdb_id?: string|int|null, tvdb_id?: string|int|null, season_number?: int|null, episode_number?: int|null, quality?: string|null, local_key?: string|null}  $parts
     */
    public static function fingerprintFor(array $parts): string
    {
        $contentType = static::normalizeContentType($parts['content_type'] ?? null);
        if ($contentType === '') {
            throw new \InvalidArgumentException('CachedContentFile::fingerprintFor() requires non-empty content_type.');
        }

        $tmdbId = (string) ($parts['tmdb_id'] ?? '');
        $tvdbId = (string) ($parts['tvdb_id'] ?? '');
        $season = $parts['season_number'] ?? null;
        $episode = $parts['episode_number'] ?? null;
        $seasonStr = ($season === null || $season === '') ? '' : (string) (int) $season;
        $episodeStr = ($episode === null || $episode === '') ? '' : (string) (int) $episode;
        $quality = strtolower(trim((string) ($parts['quality'] ?? '')));

        $base = $contentType.':'.$tmdbId.':'.$tvdbId.':'.$seasonStr.':'.$episodeStr.':'.$quality;

        $localKey = $parts['local_key'] ?? null;
        if ($localKey !== null && $localKey !== '' && $tmdbId === '' && $tvdbId === '') {
            return $base.':'.(string) $localKey;
        }

        return $base;
    }

    /**
     * Normalize a content_type value to its canonical lowercase form.
     */
    public static function normalizeContentType(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    /**
     * Cache-store key used to signal cancellation of an in-flight download
     * to the worker running DownloadCachedContentFile. Keyed by row id,
     * which is never reused.
     */
    public static function cancellationCacheKey(int $id): string
    {
        return "cached-content:cancel:{$id}";
    }

    /**
     * Source playlist that owns this cached file.
     */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    /**
     * The Channel or Episode this file was downloaded for.
     */
    public function cacheable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Filter to cached files owned by the given user.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Filter to cached files that another of the same user's playlists
     * shares with `$playlist` (`share_cache_across_playlists = true` on the
     * owning playlist). Never matches rows owned by a different user, and
     * never matches `$playlist`'s own rows.
     *
     * Accepts a Playlist or its id; the owner's user_id is resolved in a
     * subquery so hot per-row callers don't need to load the Playlist.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSharedWithPlaylist(Builder $query, Playlist|int $playlist): Builder
    {
        $playlistId = $playlist instanceof Playlist ? (int) $playlist->id : (int) $playlist;

        return $query->whereIn('playlist_id', function ($sub) use ($playlistId): void {
            $sub->select('id')
                ->from('playlists')
                ->where('share_cache_across_playlists', true)
                ->where('id', '!=', $playlistId)
                ->where('user_id', function ($userSub) use ($playlistId): void {
                    $userSub->select('user_id')
                        ->from('playlists')
                        ->where('id', $playlistId);
                });
        });
    }

    /**
     * Filter to Completed rows that can serve `$item`: the item's own row,
     * or a copy of the same content shared by another of the same user's
     * playlists. Unmatched items (no TMDB/TVDB id) have a per-item
     * fingerprint, so they only ever match their own row.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeServableFor(Builder $query, Channel|Episode $item): Builder
    {
        $playlistId = (int) $item->playlist_id;
        $fingerprint = $item->cacheFingerprint();

        return $query
            ->where('status', CachedContentFileStatus::Completed->value)
            ->where(function (Builder $q) use ($item, $playlistId, $fingerprint): void {
                $q->whereMorphedTo('cacheable', $item)
                    ->orWhere(function (Builder $shared) use ($playlistId, $fingerprint): void {
                        $shared->where('content_fingerprint', $fingerprint)
                            ->sharedWithPlaylist($playlistId);
                    });
            });
    }

    /**
     * Find the Completed cached file that can serve `$item`, preferring the
     * item's own row over a shared copy. Returns null when there is none.
     */
    public static function findServableFor(Channel|Episode $item): ?self
    {
        if (! $item->playlist_id) {
            return null;
        }

        return static::query()
            ->servableFor($item)
            ->orderByRaw('CASE WHEN cacheable_type = ? AND cacheable_id = ? THEN 0 ELSE 1 END', [$item->getMorphClass(), $item->getKey()])
            ->first();
    }

    /**
     * Whether this row is Completed and has a file path recorded.
     */
    public function hasFilePath(): bool
    {
        return $this->status === CachedContentFileStatus::Completed && ! empty($this->file_path);
    }

    /**
     * Whether this row is Completed and its file is actually present on disk.
     * Playback checks this before redirecting so a missing file falls back to
     * the live provider stream instead of a 404.
     */
    public function isPlayable(): bool
    {
        if (! $this->hasFilePath()) {
            return false;
        }

        try {
            return Storage::disk($this->resolveStorageDisk())->exists($this->file_path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Delete the file on disk (if any). Failures are swallowed; the caller
     * decides what to do with the row.
     */
    public function deleteStoredFile(): void
    {
        if (empty($this->file_path)) {
            return;
        }

        try {
            Storage::disk($this->resolveStorageDisk())->delete($this->file_path);
        } catch (\Throwable) {
            // Missing file or unwritable disk; nothing else to clean up.
        }
    }

    /**
     * Resolve the storage disk this cached file lives on.
     */
    public function resolveStorageDisk(): string
    {
        return $this->disk ?: self::DISK;
    }

    /**
     * Relative path on the cache disk for this row's file. Uses the row
     * uuid (never shared between rows), grouped by playlist.
     */
    public function storagePathFor(string $extension): string
    {
        return ($this->playlist_id ?? 'unassigned').'/'.$this->uuid.'.'.ltrim($extension, '.');
    }

    /**
     * Resolve the MIME type from the cached file's extension.
     */
    public function resolveMimeType(): string
    {
        return match (strtolower(pathinfo((string) $this->file_path, PATHINFO_EXTENSION))) {
            'mp4', 'm4v' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            'webm' => 'video/webm',
            default => 'video/mp2t',
        };
    }
}
