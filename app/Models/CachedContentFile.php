<?php

namespace App\Models;

use App\Enums\CachedContentFileStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
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
 * @property int $failure_count
 * @property bool $never_expire
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static> ownedByUser(?User $user, bool $adminBypass = false)
 */
class CachedContentFile extends Model
{
    use HasFactory;

    protected $fillable = [
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
        'failure_count',
        'never_expire',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CachedContentFileStatus::class,
            'failure_count' => 'integer',
            'never_expire' => 'boolean',
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
     * Format: content_type:tmdb_id:tvdb_id:season_number:episode_number:quality
     * - content_type: lowercased+trimmed, REQUIRED (throws if empty)
     * - quality: lowercased+trimmed
     * - tmdb_id/tvdb_id: string-coerced
     * - season_number/episode_number: (int) cast then stringified (no leading zeros)
     *
     * @param  array{content_type: string, tmdb_id?: string|int|null, tvdb_id?: string|int|null, season_number?: int|null, episode_number?: int|null, quality?: string|null}  $parts
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

        return $contentType.':'.$tmdbId.':'.$tvdbId.':'.$seasonStr.':'.$episodeStr.':'.$quality;
    }

    /**
     * Normalize a content_type value to its canonical lowercase form.
     */
    public static function normalizeContentType(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    /**
     * Cache key used to signal cancellation of an in-flight (Downloading)
     * download to the worker running DownloadCachedContentFile::handle().
     * Keyed by row id, which is never reused (Postgres bigserial), so a
     * cancelled row's key can never collide with a later, unrelated
     * dispatch for the same content.
     *
     * Checked periodically from the job's Guzzle PROGRESS callback — see
     * DownloadCachedContentFile::checkCancellation().
     */
    public static function cancellationCacheKey(int $id): string
    {
        return "dynamic-group-cache:cancel:{$id}";
    }

    /**
     * Cache key used to signal cancellation of a job that hasn't started
     * downloading yet (Pending — cancelled before a worker reclaimed it).
     * Keyed by content_fingerprint (the row itself is already deleted by
     * the time this is checked) and consumed via Cache::pull() the one
     * time DownloadCachedContentFile::handle() is about to create a fresh
     * row for it, so a stale flag can never suppress a later, unrelated
     * legitimate dispatch for the same fingerprint.
     */
    public static function pendingCancellationCacheKey(string $fingerprint): string
    {
        return "dynamic-group-cache:cancel-pending:{$fingerprint}";
    }

    /**
     * Dynamic groups that reference this cached file.
     */
    public function dynamicGroups(): BelongsToMany
    {
        return $this->belongsToMany(DynamicGroup::class, 'cached_content_file_dynamic_groups');
    }

    /**
     * Filter to cached files reachable through a DynamicGroup whose
     * owning playlist belongs to the given user.
     *
     * `cached_content_files` intentionally has no `user_id` column — one
     * file can be shared across many playlists/groups — so ownership is
     * always expressed via the pivot. `whereHas('dynamicGroups.playlist',
     * ...)` is the only correct filter and mirrors the auth pattern in
     * `CachedContentStreamController::stream()`.
     *
     * When `$adminBypass` is true OR `$user` is null, returns the query
     * unchanged. Callers MUST NOT use this scope to gate unauthenticated
     * access (Filament widgets/relation managers always have an
     * authenticated user); the null-user fallback exists so the same
     * call site works for the rare "no auth, admin bypass implied"
     * shape. `CachedContentStreamController` authenticates via playlist
     * credentials and does NOT call this scope — leave it alone.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedByUser(Builder $query, ?User $user, bool $adminBypass = false): Builder
    {
        if ($adminBypass || $user === null) {
            return $query;
        }

        return $query->whereHas(
            'dynamicGroups.playlist',
            fn (Builder $q) => $q->where('user_id', $user->id),
        );
    }

    /**
     * Whether this cached file has a completed file on disk.
     */
    public function hasFilePath(): bool
    {
        return $this->status === CachedContentFileStatus::Completed && ! empty($this->file_path);
    }

    /**
     * Resolve the storage disk this cached file lives on.
     */
    public function resolveStorageDisk(): string
    {
        return $this->disk ?: config('filesystems.default');
    }

    /**
     * Resolve the MIME type from the cached file's extension.
     */
    public function resolveMimeType(): string
    {
        return match (strtolower(pathinfo($this->file_path, PATHINFO_EXTENSION))) {
            'mp4' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            default => 'video/mp2t',
        };
    }
}
