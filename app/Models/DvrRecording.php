<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Events\DvrRecordingStatusEvent;
use App\Notifications\Notification as AppNotification;
use App\Services\ShowMetadataService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DvrRecording extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DvrRecordingStatus::class,
            'user_cancelled' => 'boolean',
            'attempt_count' => 'integer',
            'season' => 'integer',
            'episode' => 'integer',
            'scheduled_start' => UtcDateTime::class,
            'scheduled_end' => UtcDateTime::class,
            'actual_start' => UtcDateTime::class,
            'actual_end' => UtcDateTime::class,
            'duration_seconds' => 'integer',
            'file_size_bytes' => 'integer',
            'metadata' => 'array',
            'programme_start' => UtcDateTime::class,
            'programme_end' => UtcDateTime::class,
            'epg_programme_data' => 'array',
            'pid' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (DvrRecording $recording): void {
            if (empty($recording->uuid)) {
                $recording->uuid = (string) Str::uuid();
            }
        });

        static::created(function (DvrRecording $recording): void {
            $recording->broadcastStatus();
        });

        static::updated(function (DvrRecording $recording): void {
            if ($recording->wasChanged('status')) {
                $recording->broadcastStatus();

                // Once/Manual rules are one-shot — they will never match another
                // programme, so there's no reason to wait for the recording row
                // itself to be deleted before cleaning up the spent rule (unlike
                // Series rules, which stay alive for future episodes and are only
                // cleaned up on deletion, see the `deleting` hook below). Without
                // this, choosing "Keep recording" on an in-progress recording (or
                // simply letting one finish normally) left the rule enabled and
                // orphaned in the editor's Rules list forever.
                if (in_array($recording->status, [
                    DvrRecordingStatus::Completed,
                    DvrRecordingStatus::Failed,
                    DvrRecordingStatus::Cancelled,
                ], true)) {
                    self::deleteSpentOneShotRule($recording);
                }
            }
        });

        static::deleting(function (DvrRecording $recording): void {
            // Broadcast first, before any cascade/file cleanup below can fail —
            // the TV app needs to hear about a deletion regardless of whether
            // the on-disk cleanup succeeds.
            try {
                $recording->broadcastDeleted();
            } catch (\Throwable $e) {
                Log::warning("DvrRecording deleting hook: could not broadcast deletion: {$e->getMessage()}", [
                    'recording_id' => $recording->id,
                ]);
            }

            // Delete the physical file from disk using the storage facade (file_path is relative).
            if ($recording->file_path) {
                $disk = $recording->dvrSetting?->storage_disk ?: config('dvr.storage_disk', 'local');

                try {
                    if (Storage::disk($disk)->exists($recording->file_path)) {
                        Storage::disk($disk)->delete($recording->file_path);
                    }

                    // Delete comskip sidecar files: .edl, .txt, and .logo.txt
                    $basePath = preg_replace('/\.[^.]+$/', '', $recording->file_path);
                    foreach (['.edl', '.txt', '.logo.txt'] as $ext) {
                        $sidecarPath = $basePath.$ext;
                        if (Storage::disk($disk)->exists($sidecarPath)) {
                            Storage::disk($disk)->delete($sidecarPath);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("DvrRecording deleting hook: could not delete file {$recording->file_path}: {$e->getMessage()}", [
                        'recording_id' => $recording->id,
                    ]);
                }
            }

            // Cascade to VOD channel and episode inside a transaction so both nulls + deletes
            // are atomic. The dvr_recording_id is nulled first so the Channel/Episode deleting
            // hooks don't attempt to re-delete this recording (re-entrance guard).
            try {
                DB::transaction(function () use ($recording): void {
                    if ($vodChannel = $recording->vodChannel) {
                        $vodChannel->dvr_recording_id = null;
                        $vodChannel->save();
                        $vodChannel->delete();
                    }

                    if ($vodEpisode = $recording->vodEpisode) {
                        // Capture the parent IDs BEFORE the delete so we can prune
                        // an empty parent if this episode was the last in its Series
                        // (issue #1372: the TV app was listing series with no
                        // remaining episodes). FK cascade on
                        // seasons.series_id / episodes.series_id takes care of any
                        // lingering Season/Episode rows when the Series itself is
                        // removed, so we never hand-roll that teardown.
                        $seriesId = $vodEpisode->series_id;
                        $seasonId = $vodEpisode->season_id;

                        $vodEpisode->dvr_recording_id = null;
                        $vodEpisode->save();
                        $vodEpisode->delete();

                        if ($seriesId !== null && Episode::where('series_id', $seriesId)->doesntExist()) {
                            // Series has zero remaining episodes — drop it. The
                            // shared "DVR Recordings" Category is intentionally
                            // NOT touched here: findOrCreateDvrCategory() reuses
                            // one Category per playlist across every DVR series.
                            Series::find($seriesId)?->delete();
                        } elseif ($seasonId !== null && Episode::where('season_id', $seasonId)->doesntExist()) {
                            // Series survives but this episode's Season is now
                            // empty — prune the empty Season row. Seasons
                            // accumulate per series via Season::firstOrCreate,
                            // so empty rows would otherwise linger.
                            Season::find($seasonId)?->delete();
                        }
                    }
                });
            } catch (\Throwable $e) {
                Log::warning("DvrRecording deleting hook: could not cascade-delete VOD channel/episode: {$e->getMessage()}", [
                    'recording_id' => $recording->id,
                ]);
            }

            // Cascade to the recording rule that produced this recording.
            // - Once / Manual rules: always remove (one-shot).
            // - Series rules: remove only when this was the last sibling recording
            //   so an in-progress season keeps its rule alive.
            $rule = $recording->recordingRule;
            if ($rule) {
                $isOneShot = in_array($rule->type, [DvrRuleType::Once, DvrRuleType::Manual], true);
                $hasSiblings = DvrRecording::where('dvr_recording_rule_id', $rule->id)
                    ->where('id', '!=', $recording->id)
                    ->exists();

                if ($isOneShot || ! $hasSiblings) {
                    self::deleteRuleQuietly($rule, $recording, 'deleting hook');
                }
            }

            // Best-effort: prune now-empty parent directories up to (but not including)
            // the library root, so the storage tree doesn't accumulate empty Year/Title dirs.
            if ($recording->file_path) {
                $disk = $recording->dvrSetting?->storage_disk ?: config('dvr.storage_disk', 'local');
                self::pruneEmptyParentDirs($disk, $recording->file_path, 'library');
            }
        });
    }

    /**
     * Deletes a spent Once/Manual rule once its one and only recording reaches
     * a terminal status. Unlike the deletion cascade below, this never touches
     * Series rules — a completed episode doesn't mean the season is over, and
     * future sibling recordings for the same rule may not exist yet (they're
     * matched closer to their air time), so the "no siblings left" heuristic
     * that's safe on deletion would be wrong here.
     */
    private static function deleteSpentOneShotRule(self $recording): void
    {
        $rule = $recording->recordingRule;
        if (! $rule || ! in_array($rule->type, [DvrRuleType::Once, DvrRuleType::Manual], true)) {
            return;
        }

        self::deleteRuleQuietly($rule, $recording, 'updated hook');
    }

    private static function deleteRuleQuietly(DvrRecordingRule $rule, self $recording, string $context): void
    {
        try {
            $rule->delete();
        } catch (\Throwable $e) {
            Log::warning("DvrRecording {$context}: could not delete rule {$rule->id}: {$e->getMessage()}", [
                'recording_id' => $recording->id,
            ]);
        }
    }

    /**
     * Resolve the EPG programme icon URL from the stored programme data.
     */
    public function getEpgProgrammeIconAttribute(): ?string
    {
        $data = $this->epg_programme_data;

        if (! empty($data['icon'])) {
            return $data['icon'];
        }

        return null;
    }

    /**
     * Resolve the series poster URL.
     * Prefers the VOD episode's series cover, falls back to ShowMetadataService.
     */
    public function getSeriesPosterAttribute(): ?string
    {
        $episode = $this->vodEpisode;
        if ($episode && $episode->series && ! empty($episode->series->cover)) {
            return $episode->series->cover;
        }

        if (! empty($this->title)) {
            $posters = app(ShowMetadataService::class)->resolvePosters([$this->title]);

            return $posters[$this->title] ?? null;
        }

        return null;
    }

    /**
     * Resolve the episode cover URL from the VOD episode, if present.
     */
    public function getEpisodeCoverAttribute(): ?string
    {
        $episode = $this->vodEpisode;

        return $episode && ! empty($episode->cover) ? $episode->cover : null;
    }

    /**
     * Resolve the channel icon URL.
     * Prefers custom EPG icon, then standard EPG icon, then channel logo.
     */
    public function getChannelIconAttribute(): ?string
    {
        $channel = $this->channel;
        if (! $channel) {
            return null;
        }

        $epgChannel = $channel->epgChannel;
        if ($epgChannel) {
            if (! empty($epgChannel->icon_custom)) {
                return $epgChannel->icon_custom;
            }

            if (! empty($epgChannel->icon)) {
                return $epgChannel->icon;
            }
        }

        return $channel->logo ?: null;
    }

    /**
     * Pushes this recording's current status to the owning playlist's TV app
     * channel over Reverb, so clients can mark channels as recording live
     * instead of polling get_dvr_recordings. Silently no-ops when the
     * dvr setting's owning playlist can't be resolved (e.g. orphaned rows).
     */
    public function broadcastStatus(): void
    {
        $event = DvrRecordingStatusEvent::fromRecording($this);

        if ($event) {
            broadcast($event);
        }
    }

    /**
     * Pushes a `deleted` status so the TV app removes this recording from its
     * local list — the server is the source of truth, and a recording
     * deleted here (Filament, retention, delete_dvr_recording) has no other
     * signal that would otherwise tell the client it's gone.
     */
    public function broadcastDeleted(): void
    {
        $event = DvrRecordingStatusEvent::forDeletion($this);

        if ($event) {
            broadcast($event);
        }
    }

    /**
     * Sends a persisted TV notification (Notifications screen, unread badge,
     * channel-subscription filtering) for a user-facing status change. Only
     * called for transitions worth surfacing to the user — started, completed,
     * failed, cancelled — not every status change broadcastStatus() covers
     * (e.g. post_processing has no user-facing notification).
     */
    public function notifyTv(string $title, string $status): void
    {
        $playlist = $this->dvrSetting?->owner();

        if (! $playlist) {
            return;
        }

        $playlistAuth = $this->playlistAuth;

        // A guest whose credential has since been disabled, has expired, or
        // had DVR access turned off shouldn't keep receiving notifications
        // about recordings tied to their account.
        if ($playlistAuth && ! $playlistAuth->isEligibleForDvrNotifications()) {
            return;
        }

        AppNotification::make()
            ->title($title)
            ->body($this->title)
            ->status($status)
            // Owner-created recordings (null playlistAuth) notify only the
            // admin/owner channel; guest recordings notify only the guest who
            // created them — never every other guest sharing the playlist.
            ->tvBroadcast(
                $playlist,
                'dvr',
                adminOnly: $playlistAuth === null,
                playlistAuth: $playlistAuth,
            );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlistAuth(): BelongsTo
    {
        return $this->belongsTo(PlaylistAuth::class);
    }

    public function dvrSetting(): BelongsTo
    {
        return $this->belongsTo(DvrSetting::class);
    }

    public function recordingRule(): BelongsTo
    {
        return $this->belongsTo(DvrRecordingRule::class, 'dvr_recording_rule_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** The VOD Channel created from this recording (movie integration). */
    public function vodChannel(): HasOne
    {
        return $this->hasOne(Channel::class, 'dvr_recording_id');
    }

    /** The VOD Episode created from this recording (TV series integration). */
    public function vodEpisode(): HasOne
    {
        return $this->hasOne(Episode::class, 'dvr_recording_id');
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', DvrRecordingStatus::Scheduled);
    }

    public function scopeRecording(Builder $query): Builder
    {
        return $query->where('status', DvrRecordingStatus::Recording);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', DvrRecordingStatus::Completed);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', DvrRecordingStatus::Failed);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereIn('status', [
            DvrRecordingStatus::Scheduled,
            DvrRecordingStatus::Recording,
        ])->orderBy('scheduled_start');
    }

    /**
     * Whether this recording has a completed file on disk.
     */
    public function hasFilePath(): bool
    {
        return $this->status === DvrRecordingStatus::Completed && ! empty($this->file_path);
    }

    /**
     * Resolve the storage disk this recording's file lives on.
     */
    public function resolveStorageDisk(): string
    {
        return $this->dvrSetting?->storage_disk ?: config('dvr.storage_disk');
    }

    /**
     * Resolve the MIME type from the recording file's extension.
     */
    public function resolveMimeType(): string
    {
        return match (strtolower(pathinfo($this->file_path, PATHINFO_EXTENSION))) {
            'mp4' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            default => 'video/mp2t',
        };
    }

    /**
     * Build a download response for this recording's file, or null if the
     * file is missing from disk. Delegates the actual streaming to the
     * storage disk's driver rather than hand-rolling fopen/fread, so
     * missing/unreadable files are handled by Flysystem instead of risking
     * a TypeError from feof()/fread() on a failed fopen().
     */
    public function downloadResponse(): ?StreamedResponse
    {
        $disk = $this->resolveStorageDisk();

        if (! Storage::disk($disk)->exists($this->file_path)) {
            return null;
        }

        return Storage::disk($disk)->download($this->file_path, basename($this->file_path), [
            'Content-Type' => $this->resolveMimeType(),
        ]);
    }

    /**
     * Get a human-readable display title (with S/E info if available).
     */
    public function getDisplayTitleAttribute(): string
    {
        $title = $this->title;

        if ($this->season !== null && $this->episode !== null) {
            $title .= sprintf(' S%02dE%02d', $this->season, $this->episode);
        }

        if (! empty($this->subtitle)) {
            $title .= ' - '.$this->subtitle;
        }

        return $title;
    }

    /**
     * Build the attribute array used to open this recording in the floating player.
     * Mirrors Episode::getFloatingPlayerAttributes() for consistent dispatch shape.
     */
    public function getFloatingPlayerAttributes(): array
    {
        $playlist = $this->dvrSetting?->owner();
        $username = $this->user->name;
        $format = $this->status === DvrRecordingStatus::Completed
            ? ($this->dvrSetting?->dvr_output_format ?? 'mp4')
            : 'm3u8';

        $routeParams = [
            'username' => $username,
            'password' => $playlist->uuid,
            'uuid' => $this->uuid,
        ];

        return [
            'id' => 'dvr-recording-'.$this->id,
            'stream_id' => $this->id,
            'content_type' => 'dvr_recording',
            'playlist_id' => $playlist?->id,
            'title' => $this->display_title,
            'display_title' => $this->display_title,
            'url' => route('dvr.recording.stream', array_merge($routeParams, ['format' => $format])),
            'format' => $format,
            'type' => 'channel',
            'edl_url' => route('dvr.recording.edl', $routeParams),
        ];
    }

    /**
     * Whether comskip (commercial detection) should run for this recording.
     *
     * Per-rule setting takes precedence when explicitly set;
     * otherwise falls back to the DvrSetting default.
     */
    public function shouldRunComskip(): bool
    {
        $rule = $this->recordingRule;
        if ($rule && $rule->enable_comskip !== null) {
            return (bool) $rule->enable_comskip;
        }

        return $this->dvrSetting?->enable_comskip ?? false;
    }

    /**
     * Resolve the comskip .ini file path for this recording.
     *
     * If the DvrSetting specifies a custom ini path that exists on the storage
     * disk, it is used. Otherwise the bundled default is returned.
     */
    public function resolveComskipIniPath(): string
    {
        $setting = $this->dvrSetting;
        $diskName = $setting?->storage_disk ?: config('dvr.storage_disk', 'dvr');

        if ($setting && $setting->comskip_ini_path) {
            try {
                if (Storage::disk($diskName)->exists($setting->comskip_ini_path)) {
                    return Storage::disk($diskName)->path($setting->comskip_ini_path);
                }
            } catch (\Exception) {
                // Fall through to default
            }
        }

        return config('dvr.comskip_default_ini');
    }

    /**
     * Walk up from $relativePath's directory and delete each directory that is
     * empty after the file has been removed. Stops at $stopAt (exclusive) or
     * at the disk root, whichever comes first.
     *
     * The file at $relativePath is assumed to already be deleted by the caller.
     */
    private static function pruneEmptyParentDirs(string $disk, string $relativePath, string $stopAt): void
    {
        try {
            $storage = Storage::disk($disk);
            $stopAt = trim($stopAt, '/');
            $dir = trim((string) dirname($relativePath), '/');

            // Walk up: library/2024/Show -> library/2024 -> library (stop)
            while ($dir !== '' && $dir !== '.' && $dir !== $stopAt) {
                if (! $storage->exists($dir)) {
                    break;
                }

                $files = $storage->files($dir);
                $subdirs = $storage->directories($dir);

                if (! empty($files) || ! empty($subdirs)) {
                    break;
                }

                $storage->deleteDirectory($dir);

                $parent = trim((string) dirname($dir), '/');
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        } catch (\Throwable $e) {
            Log::warning("DvrRecording: pruneEmptyParentDirs failed for {$relativePath}: {$e->getMessage()}");
        }
    }
}
