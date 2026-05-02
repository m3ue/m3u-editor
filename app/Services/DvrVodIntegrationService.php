<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\Episode;
use App\Models\Group;
use App\Models\Season;
use App\Models\Series;
use App\Support\EpisodeNumberParser;
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

    public function __construct(
        private readonly TmdbService $tmdbService,
    ) {}

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
            throw $e;
        }
    }

    /**
     * Decide whether a completed recording should be classified as TV (Series/
     * Episode) or as a movie (single Channel row).
     *
     * Priority order (first match wins):
     *
     *   1. TMDB metadata type (definitive when present)
     *   2. recording.season column (set from EPG at schedule time)
     *   3. epg_programme_data.episode_num (raw XMLTV string — present even when
     *      the EPG import predated our season/episode column parsing fix)
     *   4. epg_programme_data.category — explicit "movie"/"film" → false
     *   5. epg_programme_data.category — TV-ish keywords (series / episode /
     *      tv / show / news / documentary / talk) → true
     *   6. recording.subtitle non-empty AND a category was supplied (any
     *      non-empty value other than the movie keywords above) → true.
     *      Subtitle alone is not enough because some providers tag movies
     *      with subtitles for director's cuts / extended editions; pairing
     *      with the presence of any EPG category lets us trust it as a
     *      "this aired with episodic context" hint.
     */
    private function isTvContent(DvrRecording $recording, ?array $tmdb): bool
    {
        if ($tmdb && isset($tmdb['type'])) {
            return $tmdb['type'] === 'tv';
        }

        if ($recording->season !== null) {
            return true;
        }

        // episode_num present in stored EPG data is a reliable TV signal even
        // when season/episode columns were not populated (pre-parseEpisodeNumbers fix).
        if (! empty($recording->epg_programme_data['episode_num'])) {
            return true;
        }

        $category = mb_strtolower(trim((string) ($recording->epg_programme_data['category'] ?? '')));

        if ($category !== '') {
            // Explicit movie/film category short-circuits to movie path.
            if (str_contains($category, 'movie') || str_contains($category, 'film')) {
                return false;
            }

            // TV-ish category keywords.
            foreach (['series', 'episode', 'tv', 'show', 'news', 'documentary', 'talk'] as $needle) {
                if (str_contains($category, $needle)) {
                    return true;
                }
            }

            // A non-movie EPG category was supplied (e.g. "sports",
            // "entertainment").  If the recording also has a subtitle, treat
            // it as TV — the EPG explicitly classified it as a programme,
            // not a film, and the subtitle gives us per-episode context.
            if (! empty(trim((string) $recording->subtitle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the authenticated stream URL for a DVR recording.
     *
     * Generates the same URL format as the "Watch" action:
     *   /dvr/{username}/{playlist-uuid}/{recording-uuid}.{ext}
     *
     * DvrStreamController authenticates via the playlist UUID (no session required),
     * so this URL works in any media player without additional login.
     */
    private function buildStreamUrl(DvrRecording $recording): string
    {
        $setting = $recording->dvrSetting;
        $playlist = $setting?->playlist;
        $user = $recording->user;

        if ($playlist && $user) {
            $ext = $setting->dvr_output_format ?? 'ts';

            $params = [
                'username' => $user->name,
                'password' => $playlist->uuid,
                'uuid' => $recording->uuid,
                'format' => $ext,
            ];

            // DVR_STREAM_BASE_URL lets operators override APP_URL so that
            // proxies running in separate containers can reach the editor
            // using the internal Docker hostname rather than localhost.
            $streamBase = config('dvr.stream_base_url');
            if ($streamBase) {
                return rtrim($streamBase, '/').route('dvr.recording.stream', $params, absolute: false);
            }

            return route('dvr.recording.stream', $params);
        }

        // Fallback: path-only URL (playlist or user not resolvable)
        return url('/dvr/recordings/'.$recording->uuid.'/stream');
    }

    /**
     * Create or update a VOD Channel record for a movie recording.
     */
    private function integrateAsMovie(DvrRecording $recording, int $playlistId, int $userId, ?array $tmdb): void
    {
        $streamUrl = $this->buildStreamUrl($recording);
        $containerExt = $recording->dvrSetting?->dvr_output_format ?? 'ts';
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
            $channel->container_extension = $containerExt;
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

        if (! $tmdb && empty($channel->tmdb_id)) {
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

        // When EPG provides a season but no episode number (common for daily shows),
        // derive a unique episode number from the air date (compact MMDD: Apr 21 → 421)
        // so multiple recordings of the same show never collide at E01.
        // Also fall back to episode_num heuristic parsing when recording.episode is null
        // but epg_programme_data.episode_num holds an unparsed string (pre-fix recordings).
        $episodeNumber = $recording->episode ?? $this->resolveEpisodeNumber($recording);
        $streamUrl = $this->buildStreamUrl($recording);
        $containerExt = $recording->dvrSetting?->dvr_output_format ?? 'ts';
        $sourceLogo = $this->resolveSourceChannelLogo($recording);

        // Episode-level metadata (populated by DvrMetadataEnricherService when available)
        $tmdbEpisode = is_array($recording->metadata) ? ($recording->metadata['tmdb_episode'] ?? null) : null;
        $tvmazeEpisode = is_array($recording->metadata) ? ($recording->metadata['tvmaze_episode'] ?? null) : null;

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
            $episode->container_extension = $containerExt;
            $episode->enabled = true;
            $episode->source_episode_id = null;
            $episode->import_batch_no = 'dvr';
            $episode->dvr_recording_id = $recording->id;
        }

        // Always update title, URL, and info (URL may have been created with wrong base).
        // Prefer episode-level art/plot from TMDB/TVMaze when available, falling back to
        // show-level poster and the recording's own description.
        $episode->title = $this->buildEpisodeTitle($recording);
        $episode->url = $streamUrl;
        $episode->info = [
            'plot' => $tmdbEpisode['overview'] ?? $tvmazeEpisode['summary']
                ?? $recording->description ?? $tmdb['overview'] ?? null,
            'release_date' => $tmdbEpisode['air_date'] ?? $tvmazeEpisode['airdate']
                ?? $recording->programme_start?->toDateString(),
            'duration_secs' => $recording->duration_seconds,
            'movie_image' => $tmdbEpisode['still_url'] ?? $tvmazeEpisode['image']
                ?? $tmdb['poster_url'] ?? $sourceLogo ?? null,
            'tmdb_id' => $tmdb['id'] ?? null,
            'season' => $seasonNumber,
        ];

        $episode->save();

        // Keep episode_count accurate so players show the season correctly.
        $season->episode_count = $season->episodes()->count();
        $season->save();

        Log::info('DvrVodIntegration: '.($isNew ? 'created' : 'updated')." episode {$episode->id} for recording {$recording->id}", [
            'series' => $seriesName,
            'season' => $seasonNumber,
            'episode' => $episodeNumber,
            'playlist_id' => $playlistId,
        ]);
    }

    /**
     * Resolve the episode number for a recording that has no explicit episode column.
     *
     * Tries to parse the stored epg_programme_data.episode_num string first
     * (covers recordings created before the EpgCacheService season-parse fix).
     * Falls back to a date-derived MMDD number to avoid S01E01 collisions for daily shows.
     */
    private function resolveEpisodeNumber(DvrRecording $recording): int
    {
        $raw = $recording->epg_programme_data['episode_num'] ?? null;
        if ($raw) {
            [, $parsed] = EpisodeNumberParser::fromRaw($raw);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return $this->deriveDateEpisodeNumber($recording);
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
        $date = $recording->programme_start
            ?? $recording->scheduled_start
            ?? $recording->created_at  // deterministic fallback — always set
            ?? now();

        return $date->format('M j, Y');
    }

    /**
     * Derive a compact date-based episode number (MMDD) for recordings that have
     * a season but no EPG episode number (e.g. daily news, sports events).
     *
     * Apr 21 → 421, Dec 3 → 1203.  Deterministic and sortable within a season year.
     */
    private function deriveDateEpisodeNumber(DvrRecording $recording): int
    {
        $date = $recording->programme_start
            ?? $recording->scheduled_start
            ?? $recording->created_at
            ?? now();

        return $date->month * 100 + $date->day;
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
     *
     * The name lookup is case-insensitive so that a recording with title
     * "breaking bad" and a later recording enriched with TMDB name "Breaking Bad"
     * resolve to the same series rather than creating a duplicate.
     */
    private function findOrCreateSeries(string $name, int $playlistId, int $userId, int $categoryId, ?array $tmdb, ?string $sourceLogo = null, ?array $tvmaze = null): Series
    {
        $defaults = [
            'user_id' => $userId,
            'category_id' => $categoryId,
            'enabled' => true,
            'source_series_id' => null,
            'import_batch_no' => 'dvr',
        ];

        if ($tmdb) {
            $defaults['cover'] = $tmdb['poster_url'] ?? $sourceLogo;
            $defaults['plot'] = $tmdb['overview'] ?? null;
            $defaults['release_date'] = $tmdb['first_air_date'] ?? $tmdb['release_date'] ?? null;
            $defaults['metadata'] = $tmdb;
            if (! empty($tmdb['id'])) {
                $defaults['tmdb_id'] = $tmdb['id'];
            }
        } elseif ($tvmaze) {
            $defaults['cover'] = $tvmaze['poster_url'] ?? $sourceLogo;
            $defaults['plot'] = $tvmaze['overview'] ?? null;
            $defaults['release_date'] = $tvmaze['premiered'] ?? null;
            // Persist the TVMaze blob so that a later upgrade (or de-dup) can
            // see we already have a TVMaze identity for this series.  Stored
            // under a wrapper key to leave room for future TMDB enrichment to
            // merge in alongside without overwriting.
            $defaults['metadata'] = ['tvmaze' => $tvmaze];
        } elseif ($sourceLogo) {
            $defaults['cover'] = $sourceLogo;
        }

        // Prefer matching by tmdb_id when available — this is stable across
        // title renames, locale variations, and "The X" vs "X" differences.
        // Fall back to TVMaze id (stored in metadata.tvmaze.id) for series
        // that were enriched from TVMaze only.  Final fallback is a
        // case-insensitive name match so series created before metadata
        // arrived can still be found and upgraded.
        $tmdbId = ! empty($tmdb['id']) ? (string) $tmdb['id'] : null;
        $tvmazeId = ! empty($tvmaze['id']) ? (int) $tvmaze['id'] : null;
        $normalizedName = mb_strtolower(trim($name));

        $series = null;
        if ($tmdbId !== null) {
            $series = Series::where('playlist_id', $playlistId)
                ->whereNull('source_series_id')
                ->where('tmdb_id', $tmdbId)
                ->first();
        }

        if (! $series && $tvmazeId !== null) {
            // Match on stored TVMaze id inside the metadata JSON column.  We
            // store it under metadata.tvmaze.id (see create + update branches
            // below).  Use a JSON path query so the index lookup is exact.
            $series = Series::where('playlist_id', $playlistId)
                ->whereNull('source_series_id')
                ->where('metadata->tvmaze->id', $tvmazeId)
                ->first();
        }

        if (! $series) {
            $series = Series::where('playlist_id', $playlistId)
                ->whereNull('source_series_id')
                ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
                ->first();
        }

        if (! $series) {
            $series = Series::create(array_merge(
                ['playlist_id' => $playlistId, 'source_series_id' => null, 'name' => $name],
                $defaults
            ));
        }

        if (! $series->wasRecentlyCreated) {
            // Update metadata if we now have TMDB data and didn't before
            if ($tmdb && empty($series->tmdb_id)) {
                $series->cover = $tmdb['poster_url'] ?? $series->cover;
                $series->plot = $tmdb['overview'] ?? $series->plot;
                $series->release_date = $tmdb['first_air_date'] ?? $tmdb['release_date'] ?? $series->release_date;
                $series->metadata = $tmdb;
                // Upgrade the display name to the canonical TMDB name
                $series->name = $name;

                if (! empty($tmdb['id'])) {
                    $series->tmdb_id = $tmdb['id'];
                }

                $series->save();
            } elseif ($tvmaze && empty($series->tmdb_id)) {
                // TVMaze data arrived for a series that has no TMDB identity.
                // Upgrade the canonical name to TVMaze's (passed in via $name,
                // since the caller prefers tmdb.name -> tvmaze.name -> title)
                // and merge the TVMaze blob into series.metadata so we have a
                // stable identifier for future re-runs.  Skip if we already
                // stored TVMaze data with the same id (idempotent re-runs).
                $existingMeta = is_array($series->metadata) ? $series->metadata : [];
                $existingTvmazeId = $existingMeta['tvmaze']['id'] ?? null;
                $newTvmazeId = $tvmaze['id'] ?? null;

                $changed = false;

                if ($existingTvmazeId !== $newTvmazeId) {
                    $existingMeta['tvmaze'] = $tvmaze;
                    $series->metadata = $existingMeta;
                    $changed = true;
                }

                // Only upgrade the name when the current row is still using
                // the stripped recording title (i.e. no canonical name has
                // ever been written).  Detect this by comparing against
                // TVMaze's name — if they differ and the row has no tmdb_id,
                // we presume the existing name is the un-enriched fallback.
                if (! empty($tvmaze['name']) && $series->name !== $tvmaze['name']) {
                    $series->name = $tvmaze['name'];
                    $changed = true;
                }

                if (empty($series->cover)) {
                    $series->cover = $tvmaze['poster_url'] ?? $sourceLogo ?? $series->cover;
                    $changed = true;
                }
                if (empty($series->plot)) {
                    $series->plot = $tvmaze['overview'] ?? $series->plot;
                    $changed = true;
                }
                if (empty($series->release_date)) {
                    $series->release_date = $tvmaze['premiered'] ?? $series->release_date;
                    $changed = true;
                }

                if ($changed) {
                    $series->save();
                }
            } elseif ($sourceLogo && empty($series->cover)) {
                // Backfill the source channel logo if the series has no cover yet
                $series->cover = $sourceLogo;
                $series->save();
            }
        }

        return $series;
    }

    /**
     * Find an existing season by series + season number, or create a new one.
     */
    private function findOrCreateSeason(Series $series, int $seasonNumber, int $playlistId, int $userId, int $categoryId): Season
    {
        $season = Season::firstOrCreate(
            ['series_id' => $series->id, 'season_number' => $seasonNumber],
            [
                'playlist_id' => $playlistId,
                'user_id' => $userId,
                'category_id' => $categoryId,
                'name' => 'Season '.str_pad((string) $seasonNumber, 2, '0', STR_PAD_LEFT),
                'source_season_id' => null,
                'import_batch_no' => 'dvr',
            ]
        );

        // Backfill season poster from TMDB when missing and series has a TMDB id.
        if (empty($season->cover) && ! empty($series->tmdb_id) && $this->tmdbService->isConfigured()) {
            $details = $this->tmdbService->getSeasonDetails((int) $series->tmdb_id, $seasonNumber);
            if ($details && ($details['poster_url'] ?? false)) {
                $season->cover = $details['poster_url'];
                $rawPath = $details['poster_path'] ?? null;
                $season->cover_big = $rawPath
                    ? "https://image.tmdb.org/t/p/original{$rawPath}"
                    : null;
                $season->save();
            }
        }

        return $season;
    }
}
