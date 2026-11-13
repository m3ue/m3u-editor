<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Episode;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Support\DvrLibraryFallback;
use App\Support\EpisodeNumberParser;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

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
        private readonly DvrArtworkService $artwork,
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

        $playlistId = $this->resolvePlaylistId($setting, $recording);
        $userId = $recording->user_id;

        if (! $playlistId || ! $userId) {
            Log::warning("DvrVodIntegration: cannot resolve playlist_id for recording {$recording->id} — skipping", [
                'setting_id' => $setting->id,
                'channel_id' => $recording->channel_id,
                'resolved_playlist_id' => $playlistId,
                'user_id' => $userId,
            ]);

            return;
        }

        $tmdb = $recording->metadata['tmdb'] ?? null;
        $tvmaze = is_array($recording->metadata) ? ($recording->metadata['tvmaze'] ?? null) : null;
        $isTv = $recording->isTvContent();

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
     * Resolve the real Playlist id used to key all VOD rows created from this
     * recording.
     *
     * DvrSetting uses polymorphic-ish ownership (playlist_id /
     * custom_playlist_id / merged_playlist_id — exactly one non-null). Series,
     * Channel, Episode, Group, and Category rows all require a real Playlist FK,
     * so when the setting's owner is a CustomPlaylist or MergedPlaylist we fall
     * back to the recording's channel's playlist_id. Channels always resolve
     * through to a real Playlist (a merged/custom playlist is just a virtual
     * aggregation of source playlists), so the recording's actual broadcast
     * source is the correct semantic target.
     *
     * Returns null when neither path yields a real Playlist id — caller is
     * expected to skip integration in that case.
     */
    private function resolvePlaylistId(DvrSetting $setting, DvrRecording $recording): ?int
    {
        $playlistId = $setting->playlist_id ?? $recording->channel?->playlist_id;

        return $playlistId !== null ? (int) $playlistId : null;
    }

    /**
     * Rewrite a playlist's DVR VOD stream URLs in the current signed form. Entries
     * integrated before signed URLs stored the owner's name and playlist UUID, which
     * stop working (and would leak the owner login) once UUID login is off.
     */
    public function refreshStreamUrls(Playlist $playlist): void
    {
        foreach ([Channel::class, Episode::class] as $model) {
            $model::query()
                ->where('playlist_id', $playlist->id)
                ->whereNotNull('dvr_recording_id')
                ->with('dvrRecording.dvrSetting')
                ->lazyById()
                ->each(function (Channel|Episode $item): void {
                    if ($item->dvrRecording) {
                        $item->updateQuietly(['url' => $this->buildStreamUrl($item->dvrRecording)]);
                    }
                });
        }
    }

    /**
     * Build the stream URL for a DVR recording:
     *   /dvr/signed/{recording-uuid}.{ext}?signature=...
     *
     * The URL is stored on the VOD entry and reaches whoever plays it (guests
     * included, e.g. raw M3U output or the Xtream redirect when the proxy is off),
     * so it is signed for this one recording rather than carrying the owner's
     * credentials. It works in any media player without additional login.
     */
    private function buildStreamUrl(DvrRecording $recording): string
    {
        $signedPath = URL::signedRoute('dvr.recording.stream.signed', [
            'uuid' => $recording->uuid,
            'format' => $recording->dvrSetting?->dvr_output_format ?? 'ts',
        ], absolute: false);

        // DVR_STREAM_BASE_URL lets operators override APP_URL so that
        // proxies running in separate containers can reach the editor
        // using the internal Docker hostname rather than localhost.
        $streamBase = config('dvr.stream_base_url');
        if ($streamBase) {
            return rtrim($streamBase, '/').$signedPath;
        }

        return url($signedPath);
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
        $name = $tmdb['name'] ?? $tvmaze['name'] ?? ($cleanTitle.' ('.$this->formatRecordingDate($recording).')');

        $sourceLogo = $this->resolveSourceChannelLogo($recording);
        // A match without a poster (common for obscure titles) still gets
        // generated art rather than the bare channel logo.
        $hasProviderPoster = isset($tmdb['poster_url']) || ! empty($tvmaze['poster_url']);
        $artwork = $hasProviderPoster ? null : $this->generatedArtwork($sourceLogo);
        $generatedBackdrop = $artwork && ! isset($tmdb['backdrop_url']);
        $poster = $tmdb['poster_url'] ?? $tvmaze['poster_url'] ?? $artwork['poster'] ?? $sourceLogo ?? null;

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

        // The match's title logo, so clients have it from the first catalog
        // load rather than only once someone opens the details. Looked up
        // once, when the movie is added; a re-integration keeps the one it has.
        $existingLogo = $isNew || DvrLibraryFallback::isFallback($channel->info, 'clearlogo') ? null : ($channel->info['clearlogo'] ?? null);
        $titleLogo = ! empty($tmdb['id'])
            ? ($existingLogo ?? ($isNew ? $this->tmdbTitleLogo((int) $tmdb['id'], ($tmdb['type'] ?? null) === 'tv' ? 'tv' : 'movie') : null))
            : null;

        $info = [
            'plot' => $tmdb['overview'] ?? $recording->description ?? $tvmaze['overview'] ?? $recording->recordedNote(),
            // cover_big is the poster (as for provider VOD), not the backdrop.
            'cover_big' => $poster,
            'movie_image' => $poster,
            'release_date' => $releaseDate,
            'tmdb_id' => $tmdb['id'] ?? null,
            ...$this->durationInfo($recording),
            ...array_filter([
                'backdrop_path' => isset($tmdb['backdrop_url']) ? [$tmdb['backdrop_url']] : ($generatedBackdrop ? [$artwork['backdrop']] : null),
                'clearlogo' => $titleLogo ?? $artwork['clearlogo'] ?? null,
                'genre' => $tmdb ? null : ($recording->epg_programme_data['category'] ?? null),
            ]),
        ];

        if ($tmdb) {
            $channel->logo = $tmdb['poster_url'] ?? $artwork['poster'] ?? $sourceLogo;
            if (! empty($tmdb['id'])) {
                $channel->tmdb_id = $tmdb['id'];
            }
        } elseif (! empty($tvmaze['poster_url'])) {
            $channel->logo = $tvmaze['poster_url'];
        } elseif ($artwork || $sourceLogo) {
            $channel->logo = $artwork['poster'] ?? $sourceLogo;
        }

        // Everything not from TMDB/TVMaze is a stand-in a later TMDB match may replace.
        $channel->info = DvrLibraryFallback::mark($info, array_keys(array_filter([
            'plot' => ! isset($tmdb['overview']) && ($recording->description !== null || empty($tvmaze['overview'])),
            'cover_big' => ! $hasProviderPoster,
            'movie_image' => ! $hasProviderPoster,
            'backdrop_path' => $generatedBackdrop,
            'clearlogo' => $titleLogo === null && (bool) $artwork,
            'genre' => ! $tmdb,
            'logo' => ! $hasProviderPoster,
        ])), ['logo' => $channel->logo]);

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
        // Episode rows fall back to generated art unless the show has a poster.
        $artwork = isset($tmdb['poster_url']) || ! empty($tvmaze['poster_url']) ? null : $this->generatedArtwork($sourceLogo);

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
                ?? $recording->description ?? $tmdb['overview'] ?? $recording->recordedNote(),
            'release_date' => $tmdbEpisode['air_date'] ?? $tvmazeEpisode['airdate']
                ?? $recording->programme_start?->toDateString(),
            'duration_secs' => $recording->duration_seconds,
            ...array_intersect_key($this->durationInfo($recording), ['duration' => true]),
            // A generated backdrop is 16:9 like an episode still, so it fits
            // episode rows better than a poster-shaped image.
            'movie_image' => $tmdbEpisode['still_url'] ?? $tvmazeEpisode['image']
                ?? $tmdb['poster_url'] ?? $artwork['backdrop'] ?? $sourceLogo ?? null,
            'tmdb_id' => $tmdb['id'] ?? null,
            'season' => $seasonNumber,
        ];
        $hasProviderEpisode = $tmdbEpisode !== null || $tvmazeEpisode !== null;
        $episode->info = DvrLibraryFallback::mark($episode->info, array_keys(array_filter([
            'plot' => ! $hasProviderEpisode && ($recording->description !== null || ! isset($tmdb['overview'])),
            'movie_image' => ! isset($tmdbEpisode['still_url']) && ! isset($tvmazeEpisode['image']) && ! isset($tmdb['poster_url']),
        ])));
        // get_series_info reads the episode's own plot column over info['plot'].
        $episode->plot = $episode->info['plot'];

        $episode->save();

        // A new episode moves the series up "Recently Updated" rows, the way a
        // newly recorded movie shows up among recently added movies.
        if ($isNew) {
            $series->last_modified = now();
            $series->save();
        }

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
     * individually identifiable (e.g. "CNN News (Apr 21, 2026)").
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
            return $label.' ('.$this->formatRecordingDate($recording).')';
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
     * Generated poster and backdrop for a recording no metadata provider
     * matched, with the channel logo as the title logo when it can be fetched.
     * Null without a logo.
     *
     * @return array{poster: string, backdrop: string, clearlogo: string|null}|null
     */
    private function generatedArtwork(?string $sourceLogo): ?array
    {
        if (! $sourceLogo || ! filter_var($sourceLogo, FILTER_VALIDATE_URL)) {
            return null;
        }

        return [
            'poster' => $this->artwork->url(DvrArtworkService::POSTER, $sourceLogo),
            'backdrop' => $this->artwork->url(DvrArtworkService::BACKDROP, $sourceLogo),
            // A logo that can't be fetched would only show as a broken image,
            // so clients fall back to the title text instead.
            'clearlogo' => $this->artwork->hasUsableLogo($sourceLogo) ? $sourceLogo : null,
        ];
    }

    /**
     * Series cover, backdrop and title logo from the channel logo: generated
     * art when the logo is a usable URL, otherwise the logo as the cover.
     *
     * @param  array<string, mixed>|null  $metadata  existing series metadata to keep
     * @return array<string, mixed>
     */
    private function seriesArtworkAttributes(string $sourceLogo, ?array $metadata = null): array
    {
        $artwork = $this->generatedArtwork($sourceLogo);
        $attributes = $artwork
            ? ['cover' => $artwork['poster'], 'backdrop_path' => [$artwork['backdrop']]]
            : ['cover' => $sourceLogo];
        $metadata ??= [];
        if (isset($artwork['clearlogo'])) {
            $metadata['clearlogo'] = $artwork['clearlogo'];
        }

        return [
            ...$attributes,
            'metadata' => DvrLibraryFallback::mark($metadata, ['cover', 'backdrop_path', 'clearlogo'], $attributes),
        ];
    }

    /**
     * Stores the TMDB title logo on a series that just got its match, unless
     * it already has a real one. Clients show it on the home screen, which
     * reads the catalog and never the details fetch that would fill it later.
     */
    private function applySeriesTitleLogo(Series $series, int $tmdbId): void
    {
        if (! DvrLibraryFallback::replaceable($series->metadata, 'clearlogo')) {
            return;
        }

        $logo = $this->tmdbTitleLogo($tmdbId, 'tv');
        if ($logo === null) {
            return;
        }

        $series->metadata = DvrLibraryFallback::release([...($series->metadata ?? []), 'clearlogo' => $logo], ['clearlogo']);
        $series->save();
    }

    /**
     * The TMDB title logo (clearlogo) for a match: one details lookup, null
     * when TMDB is not configured, has no logo, or the lookup fails.
     *
     * @param  'tv'|'movie'  $type
     */
    private function tmdbTitleLogo(int $tmdbId, string $type): ?string
    {
        if (! $this->tmdbService->isConfigured()) {
            return null;
        }

        try {
            $details = $type === 'tv'
                ? $this->tmdbService->getTvSeriesDetails($tmdbId)
                : $this->tmdbService->getMovieDetails($tmdbId);
        } catch (Throwable $e) {
            Log::warning('DvrVodIntegration: TMDB title logo lookup failed', ['tmdb_id' => $tmdbId, 'error' => $e->getMessage()]);

            return null;
        }

        return filled($details['logo_url'] ?? null) ? $details['logo_url'] : null;
    }

    /**
     * Series metadata once a TMDB match arrives for a series that had none:
     * the TMDB blob, keeping the stand-in bookkeeping for art the match did
     * not replace. A matched poster drops the channel-logo title logo too,
     * so the show is not labeled with the channel.
     *
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>  $tmdb
     * @return array<string, mixed>
     */
    private function upgradedSeriesMetadata(?array $metadata, array $tmdb, bool $replacedBackdrop): array
    {
        $kept = Arr::only($metadata ?? [], ['clearlogo', DvrLibraryFallback::KEY]);
        $hasPoster = ! empty($tmdb['poster_url']);
        if ($hasPoster && DvrLibraryFallback::isFallback($kept, 'clearlogo')) {
            unset($kept['clearlogo']);
        }

        return DvrLibraryFallback::release([...$tmdb, ...$kept], array_keys(array_filter([
            'cover' => $hasPoster,
            'backdrop_path' => $replacedBackdrop,
            'clearlogo' => $hasPoster,
        ])));
    }

    /**
     * The recording's length in the Xtream info shape (`duration_secs` plus an
     * `HH:MM:SS` `duration`), or nothing when it isn't known.
     *
     * @return array{duration_secs?: int, duration?: string}
     */
    private function durationInfo(DvrRecording $recording): array
    {
        $seconds = (int) $recording->duration_seconds;
        if ($seconds <= 0) {
            return [];
        }

        return [
            'duration_secs' => $seconds,
            'duration' => sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60),
        ];
    }

    /**
     * Resolve a logo from the recording's source playlist channel, in the
     * order the rest of the app shows channel logos ({@see LogoService::channelLogoCandidates()}),
     * skipping remote logos that can't be downloaded.
     */
    private function resolveSourceChannelLogo(DvrRecording $recording): ?string
    {
        $channel = $recording->channel;

        if (! $channel) {
            return null;
        }

        // The logo the rest of the app shows for the channel, or when that one
        // can't be downloaded (a dead provider link), the next it has, such as
        // the EPG icon. Local and own-server logos are taken as they are: the
        // outbound guard won't fetch them, but clients can load them.
        $candidates = LogoService::channelLogoCandidates($channel);
        foreach ($candidates as $logo) {
            $isRemote = filter_var($logo, FILTER_VALIDATE_URL) && ! str_starts_with($logo, url('/'));
            if (! $isRemote || $this->artwork->hasUsableLogo($logo)) {
                return $logo;
            }
        }

        return $candidates[0] ?? null;
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

        // A match without a poster (common for obscure titles) still gets the
        // generated art, marked as stand-ins a later TMDB fetch may replace.
        $providerPoster = $tmdb ? ($tmdb['poster_url'] ?? null) : ($tvmaze['poster_url'] ?? null);
        $standIns = ($tmdb || $tvmaze) && ! $providerPoster && $sourceLogo ? $this->seriesArtworkAttributes($sourceLogo) : [];

        if ($tmdb) {
            $defaults['cover'] = $tmdb['poster_url'] ?? $standIns['cover'] ?? $sourceLogo;
            // Clients show the backdrop on the home screen, so it is stored
            // right away rather than on the first details fetch.
            $backdrop = ! empty($tmdb['backdrop_url']) ? [$tmdb['backdrop_url']] : ($standIns['backdrop_path'] ?? null);
            if ($backdrop) {
                $defaults['backdrop_path'] = $backdrop;
            }
            $defaults['plot'] = $tmdb['overview'] ?? null;
            $defaults['release_date'] = $tmdb['first_air_date'] ?? $tmdb['release_date'] ?? null;
            $defaults['metadata'] = DvrLibraryFallback::release(
                [...$tmdb, ...($standIns['metadata'] ?? [])],
                ! empty($tmdb['backdrop_url']) ? ['backdrop_path'] : [],
            );
            if (! empty($tmdb['id'])) {
                $defaults['tmdb_id'] = $tmdb['id'];
            }
        } elseif ($tvmaze) {
            $defaults['cover'] = $tvmaze['poster_url'] ?? $standIns['cover'] ?? $sourceLogo;
            if (isset($standIns['backdrop_path'])) {
                $defaults['backdrop_path'] = $standIns['backdrop_path'];
            }
            $defaults['plot'] = $tvmaze['overview'] ?? null;
            $defaults['release_date'] = $tvmaze['premiered'] ?? null;
            // Persist the TVMaze blob so that a later upgrade (or de-dup) can
            // see we already have a TVMaze identity for this series.  Stored
            // under a wrapper key to leave room for future TMDB enrichment to
            // merge in alongside without overwriting.
            $defaults['metadata'] = ['tvmaze' => $tvmaze, ...($standIns['metadata'] ?? [])];
        } elseif ($sourceLogo) {
            $defaults = [...$defaults, ...$this->seriesArtworkAttributes($sourceLogo)];
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

            if ($tmdbId !== null) {
                $this->applySeriesTitleLogo($series, (int) $tmdbId);
            }
        }

        if (! $series->wasRecentlyCreated) {
            // Update metadata if we now have TMDB data and didn't before
            if ($tmdb && empty($series->tmdb_id)) {
                $series->cover = $tmdb['poster_url'] ?? $series->cover;
                // Only replace a stand-in (or missing) backdrop, and only with one.
                $replacesBackdrop = ! empty($tmdb['backdrop_url'])
                    && DvrLibraryFallback::replaceable($series->metadata, 'backdrop_path', $series->backdrop_path);
                if ($replacesBackdrop) {
                    $series->backdrop_path = [$tmdb['backdrop_url']];
                }
                $series->plot = $tmdb['overview'] ?? $series->plot;
                $series->release_date = $tmdb['first_air_date'] ?? $tmdb['release_date'] ?? $series->release_date;
                $series->metadata = $this->upgradedSeriesMetadata($series->metadata, $tmdb, $replacesBackdrop);
                // Upgrade the display name to the canonical TMDB name
                $series->name = $name;

                if (! empty($tmdb['id'])) {
                    $series->tmdb_id = $tmdb['id'];
                }

                $series->save();

                if ($tmdbId !== null) {
                    $this->applySeriesTitleLogo($series, (int) $tmdbId);
                }
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

                if (DvrLibraryFallback::replaceable($series->metadata, 'cover', $series->cover)) {
                    $series->cover = $tvmaze['poster_url'] ?? $series->cover ?? $sourceLogo;
                    if (! empty($tvmaze['poster_url'])) {
                        $series->metadata = DvrLibraryFallback::release($series->metadata ?? [], ['cover']);
                    }
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
            } elseif ($sourceLogo && (empty($series->cover) || $series->cover === $sourceLogo)) {
                // Backfill artwork if the series has no cover yet, or only the bare
                // channel logo an earlier integration used as its cover.
                $series->fill($this->seriesArtworkAttributes($sourceLogo, $series->metadata));
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
