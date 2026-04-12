<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\Episode;
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
        $isTv = $this->isTvContent($recording, $tmdb);

        try {
            if ($isTv) {
                $this->integrateAsSeries($recording, $playlistId, $userId, $tmdb);
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
     * Create or update a VOD Channel record for a movie recording.
     */
    private function integrateAsMovie(DvrRecording $recording, int $playlistId, int $userId, ?array $tmdb): void
    {
        $streamUrl = PlaylistService::getBaseUrl('/dvr/recordings/'.$recording->uuid.'/stream');
        $name = $tmdb['name'] ?? $recording->title;

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
            $channel->container_extension = 'ts';
            $channel->dvr_recording_id = $recording->id;
            $channel->source_id = null;
        }

        // Always update name, title, and URL (URL may have been created with wrong base)
        $channel->name = $name;
        $channel->title = $name;
        $channel->url = $streamUrl;

        if ($tmdb) {
            $channel->logo = $tmdb['poster_url'] ?? null;
            $channel->year = isset($tmdb['release_date'])
                ? substr($tmdb['release_date'], 0, 4)
                : null;
            $channel->info = [
                'plot' => $tmdb['overview'] ?? null,
                'cover_big' => $tmdb['backdrop_url'] ?? null,
                'movie_image' => $tmdb['poster_url'] ?? null,
                'release_date' => $tmdb['release_date'] ?? null,
                'tmdb_id' => $tmdb['id'] ?? null,
            ];
            if (! empty($tmdb['id'])) {
                $channel->tmdb_id = $tmdb['id'];
            }
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
    private function integrateAsSeries(DvrRecording $recording, int $playlistId, int $userId, ?array $tmdb): void
    {
        $seriesName = $tmdb['name'] ?? $recording->title;
        $seasonNumber = $recording->season ?? 1;
        $episodeNumber = $recording->episode ?? 1;
        $streamUrl = PlaylistService::getBaseUrl('/dvr/recordings/'.$recording->uuid.'/stream');

        // Find-or-create the DVR category for this playlist
        $category = $this->findOrCreateDvrCategory($playlistId, $userId);

        // Find-or-create the Series (always update metadata if tmdb available)
        $series = $this->findOrCreateSeries($seriesName, $playlistId, $userId, $category->id, $tmdb);

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
            $episode->container_extension = 'ts';
            $episode->enabled = true;
            $episode->source_episode_id = null;
            $episode->import_batch_no = 'dvr';
            $episode->dvr_recording_id = $recording->id;
        }

        // Always update title, URL, and info (URL may have been created with wrong base)
        $episode->title = $recording->subtitle ?? $recording->title;
        $episode->url = $streamUrl;
        $episode->info = [
            'plot' => $recording->description ?? ($tmdb['overview'] ?? null),
            'release_date' => $recording->programme_start?->toDateString(),
            'duration_secs' => $recording->duration_seconds,
            'movie_image' => $tmdb['poster_url'] ?? null,
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
    private function findOrCreateSeries(string $name, int $playlistId, int $userId, int $categoryId, ?array $tmdb): Series
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
            $series->cover = $tmdb['poster_url'] ?? null;
            $series->plot = $tmdb['overview'] ?? null;
            $series->release_date = $tmdb['first_air_date'] ?? $tmdb['release_date'] ?? null;
            $series->metadata = $tmdb;

            if (! empty($tmdb['id'])) {
                $series->tmdb_id = $tmdb['id'];
            }
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
