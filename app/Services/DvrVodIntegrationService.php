<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\Episode;
use App\Models\Group;
use App\Models\Season;
use App\Models\Series;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * DvrVodIntegrationService — Converts completed DVR recordings into VOD entries.
 *
 * Integration rules:
 *   - TMDB type=movie (or no metadata + no season) → Channel with is_vod=true
 *   - TMDB type=tv   (or season is set)            → Series / Season / Episode
 *
 * DVR-created records use NULL for source_id fields to avoid unique constraint
 * collisions with Xtream-imported content.
 */
class DvrVodIntegrationService
{
    private const DVR_CATEGORY_NAME = 'DVR Recordings';

    /**
     * Integrate a completed recording into the VOD library.
     */
    public function integrateRecording(DvrRecording $recording): void
    {
        $setting = $recording->dvrSetting;

        if (! $setting) {
            Log::warning("DvrVodIntegration: no DvrSetting for recording {$recording->id} — skipping");

            return;
        }

        $playlistId = $setting->playlist_id;
        $userId = $recording->user_id;

        if (! $playlistId || ! $userId) {
            Log::warning("DvrVodIntegration: missing playlist_id or user_id for recording {$recording->id} — skipping");

            return;
        }

        $tmdb = $recording->metadata['tmdb'] ?? null;
        $tvmaze = is_array($recording->metadata) ? ($recording->metadata['tvmaze'] ?? null) : null;
        $isTv = $this->isTvContent($recording, $tmdb);

        try {
            if ($isTv) {
                $this->integrateAsSeries($recording, $playlistId, $userId, $tmdb, $tvmaze);
            } else {
                $this->integrateAsMovie($recording, $playlistId, $userId, $tmdb);
            }
        } catch (Exception $e) {
            Log::error("DvrVodIntegration: failed for recording {$recording->id}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Determine whether the recording should be treated as TV series content.
     */
    private function isTvContent(DvrRecording $recording, ?array $tmdb): bool
    {
        if ($tmdb && isset($tmdb['type'])) {
            return $tmdb['type'] === 'tv';
        }

        // Fall back to structural cues when metadata is absent
        return $recording->season !== null;
    }

    /**
     * Build the stream URL for a DVR recording.
     *
     * Uses DVR_STREAM_BASE_URL when set (for Docker-internal proxy access),
     * falling back to the general url_override / APP_URL via PlaylistService.
     */
    private function buildStreamUrl(DvrRecording $recording): string
    {
        $path = '/dvr/recordings/'.$recording->uuid.'/stream';
        $base = config('dvr.stream_base_url');

        if ($base) {
            return rtrim($base, '/').$path;
        }

        return PlaylistService::getBaseUrl($path);
    }

    /**
     * Create or update a VOD Channel record for a movie recording.
     */
    private function integrateAsMovie(DvrRecording $recording, int $playlistId, int $userId, ?array $tmdb): void
    {
        $streamUrl = $this->buildStreamUrl($recording);
        $cleanTitle = $this->stripUnicodeDecorations($recording->title);
        $tvmaze = is_array($recording->metadata) ? ($recording->metadata['tvmaze'] ?? null) : null;

        // When no metadata is available use the clean title + recording date so
        // repeated recordings of the same show are individually identifiable.
        $name = $tmdb['name'] ?? $tvmaze['name'] ?? ($cleanTitle.' — '.$this->formatRecordingDate($recording));

        $sourceLogo = $this->resolveSourceChannelLogo($recording);

        // Find existing or prepare new channel
        $channel = Channel::where('dvr_recording_id', $recording->id)->first();
        $isNew = ! $channel;

        if ($isNew) {
            $channel = new Channel;
            $channel->user_id = $userId;
            $channel->playlist_id = $playlistId;
            $channel->is_vod = true;
            $channel->is_custom = true;
            $channel->enabled = true;
            $channel->container_extension = 'mp4';
            $channel->dvr_recording_id = $recording->id;
            $channel->source_id = null;

            // Place new DVR VOD channels in the "DVR Recordings" group.
            $group = $this->findOrCreateDvrVodGroup($playlistId, $userId);
            $channel->group = $group->name;
            $channel->group_id = $group->id;
        }

        // Always update name, title, and URL (URL may have been created with wrong base)
        $channel->name = $name;
        $channel->title = $name;
        $channel->url = $streamUrl;

        $releaseDate = $tmdb['release_date']
            ?? $tvmaze['premiered']
            ?? $recording->programme_start?->toDateString();

        $channel->info = [
            'plot' => $tmdb['overview'] ?? $recording->description ?? $tvmaze['overview'] ?? null,
            'cover_big' => $tmdb['backdrop_url'] ?? $sourceLogo ?? null,
            'movie_image' => $tmdb['poster_url'] ?? $tvmaze['poster_url'] ?? $sourceLogo ?? null,
            'release_date' => $releaseDate,
            'tmdb_id' => $tmdb['id'] ?? null,
        ];

        if ($tmdb) {
            $channel->logo = $tmdb['poster_url'] ?? $sourceLogo;
            if (! empty($tmdb['id'])) {
                $channel->tmdb_id = $tmdb['id'];
            }
        } elseif (! empty($tvmaze['poster_url'])) {
            $channel->logo = $tvmaze['poster_url'];
        } elseif ($sourceLogo) {
            $channel->logo = $sourceLogo;
        }

        $channel->year = $releaseDate ? substr($releaseDate, 0, 4) : null;

        if (! $tmdb) {
            $channel->tmdb_id = null;
        }

        $channel->save();

        Log::info('DvrVodIntegration: '.($isNew ? 'created' : 'updated')." VOD channel {$channel->id} for recording {$recording->id}", [
            'title' => $name,
            'playlist_id' => $playlistId,
        ]);
    }

    /**
     * Create or update Series / Season / Episode records for a TV recording.
     */
    private function integrateAsSeries(DvrRecording $recording, int $playlistId, int $userId, ?array $tmdb, ?array $tvmaze = null): void
    {
        $seriesName = $tmdb['name'] ?? $tvmaze['name'] ?? $this->stripUnicodeDecorations($recording->title);
        $seasonNumber = $recording->season ?? 1;
        $episodeNumber = $recording->episode ?? 1;
        $streamUrl = $this->buildStreamUrl($recording);
        $sourceLogo = $this->resolveSourceChannelLogo($recording);

        // Find-or-create the DVR category for this playlist
        $category = $this->findOrCreateDvrCategory($playlistId, $userId);

        // Find-or-create the Series (always update metadata if tmdb available)
        $series = $this->findOrCreateSeries($seriesName, $playlistId, $userId, $category->id, $tmdb, $sourceLogo, $tvmaze);

        // Find-or-create the Season
        $season = $this->findOrCreateSeason($series, $seasonNumber, $playlistId, $userId, $category->id);

        // Find existing episode or prepare new one
        $episode = Episode::where('dvr_recording_id', $recording->id)->first();
        $isNew = ! $episode;

        if ($isNew) {
            $episode = new Episode;
            $episode->user_id = $userId;
            $episode->playlist_id = $playlistId;
            $episode->series_id = $series->id;
            $episode->season_id = $season->id;
            $episode->season = $seasonNumber;
            $episode->episode_num = $episodeNumber;
            $episode->container_extension = 'mp4';
            $episode->enabled = true;
            $episode->source_episode_id = null;
            $episode->import_batch_no = 'dvr';
            $episode->dvr_recording_id = $recording->id;
        }

        // Always update title, URL, and info (URL may have been created with wrong base)
        $episode->title = $this->buildEpisodeTitle($recording);
        $episode->url = $streamUrl;
        $episode->info = [
            'plot' => $recording->description ?? ($tmdb['overview'] ?? null),
            'release_date' => $recording->programme_start?->toDateString(),
            'duration_secs' => $recording->duration_seconds,
            'movie_image' => $tmdb['poster_url'] ?? $sourceLogo ?? null,
            'tmdb_id' => $tmdb['id'] ?? null,
            'season' => $seasonNumber,
        ];

        $episode->save();

        Log::info('DvrVodIntegration: '.($isNew ? 'created' : 'updated')." episode {$episode->id} for recording {$recording->id}", [
            'series' => $seriesName,
            'season' => $seasonNumber,
            'episode' => $episodeNumber,
            'playlist_id' => $playlistId,
        ]);
    }

    /**
     * Build the display title for a VOD episode.
     *
     * When the recording has season/episode numbers, they are prepended so the
     * title reads "S01E03 - Subtitle" (or "S01E03 - Show Title" when no subtitle
     * is available).  Without numbering, and when no metadata was found, the
     * recording date is appended so repeated recordings of the same channel are
     * individually identifiable (e.g. "CNN News — Apr 21, 2026").
     */
    private function buildEpisodeTitle(DvrRecording $recording): string
    {
        $label = $this->stripUnicodeDecorations($recording->subtitle ?? $recording->title);

        if ($recording->season !== null && $recording->episode !== null) {
            $prefix = sprintf('S%02dE%02d', $recording->season, $recording->episode);

            return "{$prefix} - {$label}";
        }

        if ($recording->episode !== null) {
            $prefix = sprintf('E%02d', $recording->episode);

            return "{$prefix} - {$label}";
        }

        // No S/E numbers — append recording date when metadata is absent so
        // multiple recordings of the same show are individually identifiable.
        $hasMetadata = is_array($recording->metadata) && (
            ! empty($recording->metadata['tmdb']) || ! empty($recording->metadata['tvmaze'])
        );

        if (! $hasMetadata) {
            return $label.' — '.$this->formatRecordingDate($recording);
        }

        return $label;
    }

    /**
     * Strip Unicode decorations (superscripts, subscripts, emoji, etc.) from a
     * display title, preserving basic/extended Latin characters (U+0000–U+024F).
     * Collapses the extra whitespace left behind after stripping.
     */
    private function stripUnicodeDecorations(string $title): string
    {
        $stripped = preg_replace('/[^\x{0000}-\x{024F}\s]/u', '', $title) ?? $title;

        return trim((string) preg_replace('/\s{2,}/', ' ', $stripped));
    }

    /**
     * Format the recording date for use in a fallback VOD title.
     * Returns a human-readable string like "Apr 21, 2026".
     */
    private function formatRecordingDate(DvrRecording $recording): string
    {
        $date = $recording->programme_start ?? $recording->scheduled_start ?? now();

        return $date->format('M j, Y');
    }

    /**
     * Resolve a logo URL from the recording's source playlist channel.
     *
     * Priority:
     *   1. Channel::logo_internal (direct M3U logo stored locally)
     *   2. EpgChannel::icon (TVG logo from the EPG feed)
     */
    private function resolveSourceChannelLogo(DvrRecording $recording): ?string
    {
        $channel = $recording->channel;

        if (! $channel) {
            return null;
        }

        if (! empty($channel->logo_internal)) {
            return $channel->logo_internal;
        }

        $epgChannel = $channel->epgChannel ?? null;

        if ($epgChannel && ! empty($epgChannel->icon)) {
            return $epgChannel->icon;
        }

        return null;
    }

    /**
     * Find or create a VOD Group named "DVR" for the given playlist.
     * Used to categorise DVR-sourced movie VOD channels within the VOD group filter.
     */
    private function findOrCreateDvrVodGroup(int $playlistId, int $userId): Group
    {
        return Group::firstOrCreate(
            [
                'playlist_id' => $playlistId,
                'name_internal' => self::DVR_CATEGORY_NAME,
                'type' => 'vod',
            ],
            [
                'name' => self::DVR_CATEGORY_NAME,
                'user_id' => $userId,
                'enabled' => true,
                'custom' => true,
                'import_batch_no' => 'dvr',
            ]
        );
    }

    /**
     * Find or create the "DVR Recordings" category for a playlist.
     */
    private function findOrCreateDvrCategory(int $playlistId, int $userId): Category
    {
        return Category::firstOrCreate(
            [
                'playlist_id' => $playlistId,
                'name_internal' => self::DVR_CATEGORY_NAME,
            ],
            [
                'name' => self::DVR_CATEGORY_NAME,
                'user_id' => $userId,
                'enabled' => true,
            ]
        );
    }

    /**
     * Find an existing DVR series by name + playlist, or create a new one.
     */
    private function findOrCreateSeries(string $name, int $playlistId, int $userId, int $categoryId, ?array $tmdb, ?string $sourceLogo = null, ?array $tvmaze = null): Series
    {
        // Look for an existing DVR-created series by name + playlist
        // (source_series_id is NULL for DVR series, so we match on name)
        $series = Series::where('playlist_id', $playlistId)
            ->whereNull('source_series_id')
            ->where('name', $name)
            ->first();

        if ($series) {
            // Update metadata if we now have TMDB data and didn't before
            if ($tmdb && empty($series->tmdb_id)) {
                $series->cover = $tmdb['poster_url'] ?? $series->cover;
                $series->plot = $tmdb['overview'] ?? $series->plot;
                $series->release_date = $tmdb['first_air_date'] ?? $tmdb['release_date'] ?? $series->release_date;
                $series->metadata = $tmdb;

                if (! empty($tmdb['id'])) {
                    $series->tmdb_id = $tmdb['id'];
                }

                $series->save();
            } elseif ($tvmaze && empty($series->cover) && empty($series->plot)) {
                // Backfill TVMaze metadata if series still has nothing
                $series->cover = $tvmaze['poster_url'] ?? $sourceLogo ?? $series->cover;
                $series->plot = $tvmaze['overview'] ?? $series->plot;
                $series->release_date = $tvmaze['premiered'] ?? $series->release_date;
                $series->save();
            } elseif ($sourceLogo && empty($series->cover)) {
                // Backfill the source channel logo if the series has no cover yet
                $series->cover = $sourceLogo;
                $series->save();
            }

            return $series;
        }

        $series = new Series;
        $series->name = $name;
        $series->user_id = $userId;
        $series->playlist_id = $playlistId;
        $series->category_id = $categoryId;
        $series->enabled = true;
        $series->source_series_id = null;
        $series->import_batch_no = 'dvr';

        if ($tmdb) {
            $series->cover = $tmdb['poster_url'] ?? $sourceLogo;
            $series->plot = $tmdb['overview'] ?? null;
            $series->release_date = $tmdb['first_air_date'] ?? $tmdb['release_date'] ?? null;
            $series->metadata = $tmdb;

            if (! empty($tmdb['id'])) {
                $series->tmdb_id = $tmdb['id'];
            }
        } elseif ($tvmaze) {
            $series->cover = $tvmaze['poster_url'] ?? $sourceLogo;
            $series->plot = $tvmaze['overview'] ?? null;
            $series->release_date = $tvmaze['premiered'] ?? null;
        } elseif ($sourceLogo) {
            $series->cover = $sourceLogo;
        }

        $series->save();

        return $series;
    }

    /**
     * Find an existing season by series + season number, or create a new one.
     */
    private function findOrCreateSeason(Series $series, int $seasonNumber, int $playlistId, int $userId, int $categoryId): Season
    {
        $season = Season::where('series_id', $series->id)
            ->where('season_number', $seasonNumber)
            ->first();

        if ($season) {
            return $season;
        }

        $season = new Season;
        $season->series_id = $series->id;
        $season->playlist_id = $playlistId;
        $season->user_id = $userId;
        $season->category_id = $categoryId;
        $season->season_number = $seasonNumber;
        $season->name = 'Season '.str_pad((string) $seasonNumber, 2, '0', STR_PAD_LEFT);
        $season->source_season_id = null;
        $season->import_batch_no = 'dvr';

        $season->save();

        return $season;
    }
}
