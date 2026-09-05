<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\EmbyLibraryMapping;
use App\Models\Episode;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Str;

class EmbyPublicationCatalogService
{
    /**
     * @return array<string, mixed>
     */
    public function buildForUser(
        User $user,
        ?string $username = null,
        ?string $password = null,
    ): array {
        $mappings = [];
        $query = EmbyLibraryMapping::query()
            ->whereBelongsTo($user)
            ->where('enabled', true)
            ->whereHas('integration', fn ($integrationQuery) => $integrationQuery
                ->where('type', 'emby')
                ->where('enabled', true));

        foreach ($query->lazyById(100) as $mapping) {
            if ($mapping->is_managed && $mapping->target_library_id === null) {
                $mapping->updateQuietly([
                    'last_planned_revision' => null,
                    'status' => 'pending',
                    'status_summary' => __('Pending'),
                    'error_summary' => null,
                ]);

                continue;
            }

            $catalog = $this->buildMapping($mapping, $username, $password);
            $mapping->updateQuietly([
                'last_planned_revision' => $catalog['revision'],
                'status' => 'planned',
                'status_summary' => count($catalog['items']).' top-level items planned.',
                'error_summary' => null,
            ]);
            $mappings[] = $catalog;
        }

        usort($mappings, fn (array $left, array $right): int => $left['mapping_uuid'] <=> $right['mapping_uuid']);

        return $this->withRevision([
            'api_version' => 1,
            'full_snapshot' => true,
            'mappings' => $mappings,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildMapping(
        EmbyLibraryMapping $mapping,
        ?string $username = null,
        ?string $password = null,
    ): array {
        $items = $mapping->collection_type === 'movies'
            ? $this->buildMovies($mapping, $username, $password)
            : $this->buildSeries($mapping, $username, $password);

        return $this->withRevision([
            'mapping_uuid' => $mapping->uuid,
            'integration_id' => $mapping->media_server_integration_id,
            'target_library' => [
                'id' => $mapping->target_library_id,
                'name' => $mapping->target_library_name,
                'collection_type' => $mapping->collection_type,
                'output_path' => $mapping->output_path,
                // The companion owns published files even when the Emby library already existed.
                'managed' => true,
            ],
            'options' => $mapping->options,
            'full_snapshot' => true,
            'items' => $items,
        ]);
    }

    /**
     * Append the sha256 revision hash of a catalog payload to itself. The hash
     * always covers the payload *without* the 'revision' key, so an unchanged
     * catalog always hashes to the same value.
     *
     * @param  array<string, mixed>  $catalog
     * @return array<string, mixed>
     */
    private function withRevision(array $catalog): array
    {
        $catalog['revision'] = hash(
            'sha256',
            json_encode($catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        return $catalog;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildMovies(
        EmbyLibraryMapping $mapping,
        ?string $username,
        ?string $password,
    ): array {
        $query = Channel::query()
            ->where('user_id', $mapping->user_id)
            ->where('enabled', true)
            ->where('is_vod', true)
            ->with(['playlist', 'user', 'failoverChannels.playlist', 'failoverChannels.user'])
            ->orderBy('id');

        if ($mapping->source_kind === 'vod_group') {
            $query->where('group_id', $mapping->source_identifier);
        } elseif ($mapping->source_kind === 'custom_playlist_group') {
            $customPlaylist = CustomPlaylist::query()
                ->where('user_id', $mapping->user_id)
                ->find($mapping->source_identifier);

            if ($customPlaylist === null) {
                return [];
            }

            $query
                ->whereIn('channels.id', $customPlaylist->channels()->select('channels.id'))
                ->where(function ($groupQuery) use ($customPlaylist, $mapping): void {
                    $groupQuery
                        ->whereHas('tags', fn ($tagQuery) => $tagQuery
                            ->where('type', $customPlaylist->uuid)
                            ->where('name->en', $mapping->source_label))
                        ->orWhere(function ($fallbackQuery) use ($customPlaylist, $mapping): void {
                            $fallbackQuery
                                ->where('channels.group', $mapping->source_label)
                                ->whereDoesntHave('tags', fn ($tagQuery) => $tagQuery
                                    ->where('type', $customPlaylist->uuid));
                        });
                });
        } elseif ($mapping->source_kind !== 'all') {
            return [];
        }

        $items = [];

        foreach ($query->lazyById(500) as $channel) {
            $canonicalId = $this->movieCanonicalId($channel);
            $variantKey = $this->variantKey($channel);

            if (! isset($items[$canonicalId])) {
                $items[$canonicalId] = $this->movieItem($channel, $mapping, $canonicalId);
            }

            $items[$canonicalId]['variants'][$variantKey][] = [
                'source_id' => $channel->id,
                'source_priority' => 0,
                'sort' => (float) ($channel->sort ?? 0),
                'playback_url' => $channel->getProxyUrl(
                    username: $username,
                    password: $password,
                ),
                'playlist_id' => $channel->playlist_id,
                'technical_metadata' => $channel->stream_stats,
            ];

            foreach ($channel->failoverChannels as $index => $failoverChannel) {
                if (! $failoverChannel->enabled || $failoverChannel->user_id !== $mapping->user_id) {
                    continue;
                }

                $failoverVariantKey = $this->variantKey($failoverChannel);
                $existingSourceIds = array_column(
                    $items[$canonicalId]['variants'][$failoverVariantKey] ?? [],
                    'source_id',
                );

                if (in_array($failoverChannel->id, $existingSourceIds, true)) {
                    continue;
                }

                $items[$canonicalId]['variants'][$failoverVariantKey][] = [
                    'source_id' => $failoverChannel->id,
                    'source_priority' => 1,
                    'sort' => $index,
                    'playback_url' => $failoverChannel->getProxyUrl(
                        username: $username,
                        password: $password,
                    ),
                    'playlist_id' => $failoverChannel->playlist_id,
                    'technical_metadata' => $failoverChannel->stream_stats,
                ];
            }
        }

        ksort($items, SORT_STRING);

        return array_values(array_map(function (array $item): array {
            $item['variants'] = $this->formatVariants($item['variants']);

            return $item;
        }, $items));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSeries(
        EmbyLibraryMapping $mapping,
        ?string $username,
        ?string $password,
    ): array {
        $query = Series::query()
            ->where('user_id', $mapping->user_id)
            ->where('enabled', true)
            ->with([
                'category',
                'episodes' => fn ($query) => $query
                    ->where('enabled', true)
                    ->with(['playlist', 'user', 'failoverEpisodes.playlist', 'failoverEpisodes.user'])
                    ->orderBy('season')
                    ->orderBy('episode_num')
                    ->orderBy('id'),
            ])
            ->orderBy('id');

        if ($mapping->source_kind === 'series_category') {
            $query->where('category_id', $mapping->source_identifier);
        } elseif ($mapping->source_kind === 'custom_playlist_group') {
            $customPlaylist = CustomPlaylist::query()
                ->where('user_id', $mapping->user_id)
                ->find($mapping->source_identifier);

            if ($customPlaylist === null) {
                return [];
            }

            $categoryTagType = $customPlaylist->uuid.'-category';
            $query
                ->whereIn('series.id', $customPlaylist->series()->select('series.id'))
                ->where(function ($groupQuery) use ($categoryTagType, $mapping): void {
                    $groupQuery
                        ->whereHas('tags', fn ($tagQuery) => $tagQuery
                            ->where('type', $categoryTagType)
                            ->where('name->en', $mapping->source_label))
                        ->orWhere(function ($fallbackQuery) use ($categoryTagType, $mapping): void {
                            $fallbackQuery
                                ->whereHas('category', fn ($categoryQuery) => $categoryQuery
                                    ->where('name', $mapping->source_label))
                                ->whereDoesntHave('tags', fn ($tagQuery) => $tagQuery
                                    ->where('type', $categoryTagType));
                        });
                });
        } elseif ($mapping->source_kind !== 'all') {
            return [];
        }

        $items = [];

        foreach ($query->lazyById(100) as $series) {
            $canonicalId = $this->seriesCanonicalId($series);

            if (! isset($items[$canonicalId])) {
                $items[$canonicalId] = $this->seriesItem($series, $mapping, $canonicalId);
            }

            foreach ($series->episodes as $episode) {
                $episodeItem = $this->episodeItem(
                    $episode,
                    $series,
                    $canonicalId,
                    $username,
                    $password,
                );
                $episodeCanonicalId = $episodeItem['canonical_id'];

                if (! isset($items[$canonicalId]['episodes'][$episodeCanonicalId])) {
                    $items[$canonicalId]['episodes'][$episodeCanonicalId] = $episodeItem;

                    continue;
                }

                $items[$canonicalId]['episodes'][$episodeCanonicalId]['variants'] = $this->mergeEpisodeVariants(
                    $items[$canonicalId]['episodes'][$episodeCanonicalId]['variants'],
                    $episodeItem['variants'],
                );
            }
        }

        ksort($items, SORT_STRING);

        foreach ($items as &$item) {
            $item['episodes'] = array_values($item['episodes']);
            usort($item['episodes'], fn (array $left, array $right): int => [
                $left['season_number'],
                $left['episode_number'],
                $left['canonical_id'],
            ] <=> [
                $right['season_number'],
                $right['episode_number'],
                $right['canonical_id'],
            ]);
        }
        unset($item);

        return array_values($items);
    }

    /**
     * @param  list<array<string, mixed>>  $existingVariants
     * @param  list<array<string, mixed>>  $additionalVariants
     * @return list<array<string, mixed>>
     */
    private function mergeEpisodeVariants(array $existingVariants, array $additionalVariants): array
    {
        $variants = collect($existingVariants)->keyBy('key')->all();

        foreach ($additionalVariants as $additionalVariant) {
            $key = $additionalVariant['key'];
            if (! isset($variants[$key])) {
                $variants[$key] = $additionalVariant;

                continue;
            }

            $sourceIds = array_column([
                $variants[$key]['preferred'],
                ...$variants[$key]['failover'],
            ], 'source_id');

            foreach ([$additionalVariant['preferred'], ...$additionalVariant['failover']] as $source) {
                if (in_array($source['source_id'], $sourceIds, true)) {
                    continue;
                }

                $variants[$key]['failover'][] = $source;
                $sourceIds[] = $source['source_id'];
            }
        }

        ksort($variants, SORT_STRING);

        return array_values($variants);
    }

    private function seriesCanonicalId(Series $series): string
    {
        $year = $this->seriesYear($series) ?? 'unknown';
        $fallback = 'series:title:'.$this->safeComponent($series->name).':'.$year.':'.hash('sha256', (string) $series->id);

        return $this->canonicalIdFromIds('series', $this->seriesIds($series), $fallback);
    }

    /**
     * @return array{tmdb: int|null, tvdb: int|null, imdb: string|null}
     */
    private function seriesIds(Series $series): array
    {
        $metadata = $series->metadata ?? [];

        return $this->normalizeIds(
            $series->tmdb_id ?? $metadata['tmdb_id'] ?? $metadata['tmdb'] ?? null,
            $series->tvdb_id ?? $metadata['tvdb_id'] ?? $metadata['tvdb'] ?? null,
            $series->imdb_id ?? $metadata['imdb_id'] ?? $metadata['imdb'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function seriesItem(Series $series, EmbyLibraryMapping $mapping, string $canonicalId): array
    {
        $metadata = $series->metadata ?? [];
        $year = $this->seriesYear($series);
        $ids = $this->seriesIds($series);
        $originalTitle = (string) ($metadata['original_name'] ?? $metadata['original_title'] ?? $series->name);
        $originalTitleSource = match (true) {
            isset($metadata['original_name']) => 'metadata.original_name',
            isset($metadata['original_title']) => 'metadata.original_title',
            default => 'series.name',
        };
        $relativeFolder = $this->safeComponent(trim($series->name.' '.($year ?? '')));

        return [
            'canonical_id' => $canonicalId,
            'media_type' => 'series',
            'display_title' => $series->name,
            'display_title_source' => 'series.name',
            'original_title' => $originalTitle,
            'original_title_source' => $originalTitleSource,
            'year' => $year,
            'ids' => $ids,
            'groups' => [$mapping->source_label],
            'relative_folder' => $relativeFolder,
            'base_filename' => $relativeFolder,
            'nfo' => [
                'title' => $series->name,
                'original_title' => $originalTitle,
                'year' => $year,
                'plot' => $series->plot,
                'genres' => array_values(array_filter(array_map('trim', explode(',', (string) $series->genre)))),
                'ids' => $ids,
            ],
            'episodes' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function episodeItem(
        Episode $episode,
        Series $series,
        string $seriesCanonicalId,
        ?string $username,
        ?string $password,
    ): array {
        $ids = $this->episodeIds($episode);
        $seasonNumber = (int) ($episode->season ?? 0);
        $episodeNumber = (int) ($episode->episode_num ?? 0);
        $fallback = $seasonNumber > 0 && $episodeNumber > 0
            ? sprintf('episode:%s:s%02de%02d', $seriesCanonicalId, $seasonNumber, $episodeNumber)
            : 'episode:source:'.hash('sha256', (string) $episode->id);
        $canonicalId = $this->canonicalIdFromIds('episode', $ids, $fallback);
        $info = $episode->info ?? [];
        $originalTitle = (string) ($info['original_title'] ?? $episode->title);
        $variants = [
            $this->variantKey($episode) => [[
                'source_id' => $episode->id,
                'source_priority' => 0,
                'sort' => 0,
                'playback_url' => $episode->getProxyUrl(username: $username, password: $password),
                'playlist_id' => $episode->playlist_id,
                'technical_metadata' => $episode->stream_stats ?? [],
            ]],
        ];

        foreach ($episode->failoverEpisodes as $index => $failoverEpisode) {
            if (! $failoverEpisode->enabled || $failoverEpisode->user_id !== $episode->user_id) {
                continue;
            }

            $variants[$this->variantKey($failoverEpisode)][] = [
                'source_id' => $failoverEpisode->id,
                'source_priority' => 1,
                'sort' => $index,
                'playback_url' => $failoverEpisode->getProxyUrl(username: $username, password: $password),
                'playlist_id' => $failoverEpisode->playlist_id,
                'technical_metadata' => $failoverEpisode->stream_stats ?? [],
            ];
        }

        return [
            'canonical_id' => $canonicalId,
            'series_canonical_id' => $seriesCanonicalId,
            'media_type' => 'episode',
            'display_title' => $episode->title,
            'display_title_source' => 'episode.title',
            'original_title' => $originalTitle,
            'original_title_source' => isset($info['original_title']) ? 'info.original_title' : 'episode.title',
            'season_number' => $seasonNumber,
            'episode_number' => $episodeNumber,
            'ids' => $ids,
            'groups' => [$series->category?->name ?? ''],
            'relative_folder' => sprintf('season-%02d', $seasonNumber),
            'base_filename' => $this->safeComponent(sprintf(
                '%s-s%02de%02d-%s',
                $series->name,
                $seasonNumber,
                $episodeNumber,
                $episode->title,
            )),
            'nfo' => [
                'title' => $episode->title,
                'original_title' => $originalTitle,
                'plot' => $info['plot'] ?? null,
                'season_number' => $seasonNumber,
                'episode_number' => $episodeNumber,
                'ids' => $ids,
            ],
            'variants' => $this->formatVariants($variants),
        ];
    }

    /**
     * @return array{tmdb: int|null, tvdb: int|null, imdb: string|null}
     */
    private function episodeIds(Episode $episode): array
    {
        $info = $episode->info ?? [];

        return $this->normalizeIds(
            $episode->tmdb_id ?? $info['tmdb_id'] ?? $info['tmdb'] ?? null,
            $info['tvdb_id'] ?? $info['tvdb'] ?? null,
            $info['imdb_id'] ?? $info['imdb'] ?? null,
        );
    }

    private function seriesYear(Series $series): ?int
    {
        $value = $series->release_date ?? $series->metadata['year'] ?? null;

        if (is_string($value) && preg_match('/^(\d{4})/', $value, $matches) === 1) {
            return (int) $matches[1];
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function movieCanonicalId(Channel $channel): string
    {
        $title = $this->displayTitle($channel);
        $year = $this->movieYear($channel) ?? 'unknown';
        $sourceIdentity = $channel->uuid ?: (string) $channel->id;
        $fallback = 'movie:title:'.$this->safeComponent($title).':'.$year.':'.hash('sha256', $sourceIdentity);

        return $this->canonicalIdFromIds('movie', $this->movieIds($channel), $fallback);
    }

    /**
     * @return array{tmdb: int|null, tvdb: int|null, imdb: string|null}
     */
    private function movieIds(Channel $channel): array
    {
        $info = $channel->info;
        $movieData = $channel->movie_data;

        return $this->normalizeIds(
            $channel->tmdb_id ?? $info['tmdb_id'] ?? $info['tmdb'] ?? $movieData['tmdb_id'] ?? null,
            $channel->tvdb_id ?? $info['tvdb_id'] ?? $info['tvdb'] ?? $movieData['tvdb_id'] ?? null,
            $channel->imdb_id ?? $info['imdb_id'] ?? $info['imdb'] ?? $movieData['imdb_id'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function movieItem(Channel $channel, EmbyLibraryMapping $mapping, string $canonicalId): array
    {
        $info = $channel->info;
        $movieData = $channel->movie_data;
        $displayTitle = $this->displayTitle($channel);
        [$originalTitle, $originalTitleSource] = $this->originalTitle($channel, $displayTitle);
        $year = $this->movieYear($channel);
        $ids = $this->movieIds($channel);
        $component = $this->safeComponent(trim($displayTitle.' '.($year ?? '')));

        return [
            'canonical_id' => $canonicalId,
            'media_type' => 'movie',
            'display_title' => $displayTitle,
            'display_title_source' => $channel->title_custom !== null ? 'channel.title_custom' : 'channel.title',
            'original_title' => $originalTitle,
            'original_title_source' => $originalTitleSource,
            'year' => $year,
            'ids' => $ids,
            'groups' => [$mapping->source_label],
            'relative_folder' => $component,
            'base_filename' => $component,
            'nfo' => [
                'title' => $displayTitle,
                'original_title' => $originalTitle,
                'year' => $year,
                'plot' => $info['plot'] ?? $movieData['plot'] ?? $movieData['description'] ?? null,
                'genres' => $info['genres'] ?? $movieData['genre'] ?? [],
                'ids' => $ids,
            ],
            'variants' => [],
        ];
    }

    private function displayTitle(Channel $channel): string
    {
        return trim((string) ($channel->title_custom ?? $channel->title ?? $channel->name_custom ?? $channel->name));
    }

    /**
     * @return array{string, string}
     */
    private function originalTitle(Channel $channel, string $displayTitle): array
    {
        if (! empty($channel->info['original_title'])) {
            return [(string) $channel->info['original_title'], 'info.original_title'];
        }

        if (! empty($channel->movie_data['original_title'])) {
            return [(string) $channel->movie_data['original_title'], 'movie_data.original_title'];
        }

        return [$displayTitle, 'display_title'];
    }

    private function movieYear(Channel $channel): ?int
    {
        $value = $channel->year ?? $channel->info['year'] ?? $channel->movie_data['year'] ?? null;

        if (is_numeric($value)) {
            return (int) substr((string) $value, 0, 4);
        }

        if (is_string($value) && preg_match('/^(\d{4})/', $value, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function variantKey(Channel|Episode $source): string
    {
        $video = null;
        $audio = null;

        foreach ($source->stream_stats ?? [] as $entry) {
            $stream = $entry['stream'] ?? null;

            if (($stream['codec_type'] ?? null) === 'video' && $video === null) {
                $video = $stream;
            }

            if (($stream['codec_type'] ?? null) === 'audio' && $audio === null) {
                $audio = $stream;
            }
        }

        $height = isset($video['height']) ? (int) $video['height'] : null;
        $resolution = match (true) {
            $height >= 2160 => '2160p',
            $height >= 1440 => '1440p',
            $height >= 1080 => '1080p',
            $height >= 720 => '720p',
            $height > 0 => $height.'p',
            default => 'unknown',
        };
        $transfer = Str::lower((string) ($video['color_transfer'] ?? ''));
        $hdr = match (true) {
            $transfer === '' => 'unknown',
            in_array($transfer, ['smpte2084', 'arib-std-b67'], true) => 'hdr',
            default => 'sdr',
        };

        return implode('-', [
            $resolution,
            $hdr,
            $this->safeComponent((string) ($video['codec_name'] ?? 'unknown')),
            $this->safeComponent((string) ($audio['codec_name'] ?? 'unknown')),
            $this->safeComponent((string) ($audio['tags']['language'] ?? 'unknown')),
            $this->safeComponent((string) ($source instanceof Channel ? ($source->edition ?? 'unknown') : 'unknown')),
        ]);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $groupedSources
     * @return list<array<string, mixed>>
     */
    private function formatVariants(array $groupedSources): array
    {
        ksort($groupedSources, SORT_STRING);
        $variants = [];
        $withoutInternalKeys = function (array $source): array {
            unset($source['source_priority'], $source['sort'], $source['technical_metadata']);

            return $source;
        };

        foreach ($groupedSources as $key => $sources) {
            usort($sources, fn (array $left, array $right): int => [
                $left['source_priority'],
                $left['sort'],
                $left['source_id'],
            ] <=> [
                $right['source_priority'],
                $right['sort'],
                $right['source_id'],
            ]);
            $preferred = array_shift($sources);

            $variants[] = [
                'key' => $key,
                'preferred' => $withoutInternalKeys($preferred),
                'failover' => array_map($withoutInternalKeys, $sources),
                'technical_metadata' => $preferred['technical_metadata'],
            ];
        }

        return $variants;
    }

    private function safeComponent(string $value): string
    {
        $component = Str::slug(Str::limit($value, 150, ''));

        return $component !== '' ? $component : 'unknown';
    }

    private function integerId(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function stringId(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * Normalize a movie/series/episode's raw tmdb/tvdb/imdb field values into
     * the shared {tmdb, tvdb, imdb} shape. Callers pull the raw values from
     * whichever model-specific fields apply (Channel/Series/Episode each
     * carry them under different property/metadata-array names), but the
     * type coercion is identical everywhere, so it lives here once.
     *
     * @return array{tmdb: int|null, tvdb: int|null, imdb: string|null}
     */
    private function normalizeIds(mixed $tmdb, mixed $tvdb, mixed $imdb): array
    {
        return [
            'tmdb' => $this->integerId($tmdb),
            'tvdb' => $this->integerId($tvdb),
            'imdb' => $this->stringId($imdb),
        ];
    }

    /**
     * Build a canonical ID from the shared tmdb -> tvdb -> imdb priority
     * cascade, falling back to $fallback when none of the three are present.
     * Shared by movie/series/episode canonical-ID resolution so the priority
     * order can't silently drift between media types.
     *
     * @param  array{tmdb: int|null, tvdb: int|null, imdb: string|null}  $ids
     */
    private function canonicalIdFromIds(string $mediaType, array $ids, string $fallback): string
    {
        if ($ids['tmdb'] !== null) {
            return "{$mediaType}:tmdb:{$ids['tmdb']}";
        }

        if ($ids['tvdb'] !== null) {
            return "{$mediaType}:tvdb:{$ids['tvdb']}";
        }

        if ($ids['imdb'] !== null) {
            return "{$mediaType}:imdb:".Str::lower($ids['imdb']);
        }

        return $fallback;
    }
}
