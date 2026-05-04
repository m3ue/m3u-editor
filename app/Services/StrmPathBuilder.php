<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Season;
use App\Models\Series;
use App\Models\StreamFileSetting;

class StrmPathBuilder
{
    public function __construct(
        private VodFileNameService $vodFileNameService,
        private SerieFileNameService $serieFileNameService,
    ) {}

    public function buildVodPath(Channel $channel, StreamFileSetting $setting, array $syncSettings): string
    {
        $syncLocation = rtrim($syncSettings['sync_location'] ?? '', '/');
        $pathStructure = $syncSettings['path_structure'] ?? ['group', 'title'];
        $replaceChar = $syncSettings['replace_char'] ?? 'space';
        $cleanSpecialChars = $syncSettings['clean_special_chars'] ?? false;

        $path = $syncLocation;

        if (in_array('group', $pathStructure)) {
            $groupModel = $channel->relationLoaded('group')
                ? $channel->getRelation('group')
                : $channel->group()->first();
            $groupName = $channel->group ?? $groupModel?->name ?? $groupModel?->name_internal ?? 'Uncategorized';
            $groupFolder = $cleanSpecialChars
                ? PlaylistService::makeFilesystemSafe($groupName, $replaceChar)
                : PlaylistService::makeFilesystemSafe($groupName);
            $path .= '/'.$groupFolder;
        }

        $fileName = $this->vodFileNameService->generateMovieFileName($channel, $setting);

        if (in_array('title', $pathStructure)) {
            $folderName = $this->buildVodTitleFolder($channel, $fileName, $syncSettings);
            $path .= '/'.$folderName;
        }

        return $path.'/'.$fileName.'.strm';
    }

    private function buildVodTitleFolder(Channel $channel, string $fileName, array $syncSettings): string
    {
        $folderMetadata = $syncSettings['folder_metadata'] ?? [];
        $replaceChar = $syncSettings['replace_char'] ?? 'space';
        $cleanSpecialChars = $syncSettings['clean_special_chars'] ?? false;
        $tmdbIdFormat = $syncSettings['tmdb_id_format'] ?? 'square';

        $folder = $fileName;

        if (in_array('year', $folderMetadata) && ! empty($channel->year)) {
            if (strpos($folder, "({$channel->year})") === false) {
                $folder .= " ({$channel->year})";
            }
        }

        if (in_array('tmdb_id', $folderMetadata)) {
            $tmdbId = $channel->getTmdbId();
            $imdbId = $channel->getImdbId();

            $bracket = $tmdbIdFormat === 'curly' ? ['{', '}'] : ['[', ']'];
            if (! empty($tmdbId)) {
                $folder .= " {$bracket[0]}tmdb-{$tmdbId}{$bracket[1]}";
            } elseif (! empty($imdbId)) {
                $folder .= " {$bracket[0]}imdb-{$imdbId}{$bracket[1]}";
            }
        }

        return $cleanSpecialChars
            ? PlaylistService::makeFilesystemSafe($folder, $replaceChar)
            : PlaylistService::makeFilesystemSafe($folder);
    }

    public function buildEpisodePath(Episode $episode, StreamFileSetting $setting, array $syncSettings): string
    {
        // NOTE: cannot use $episode->loadMissing(['season.series', 'series'])
        // because Episode has BOTH a `season` integer column (cast) AND a `season()` BelongsTo relation.
        // Laravel's loadMissing traverses via __get('season') which returns the integer cast,
        // then calls relationLoaded() on the int and crashes.
        // Load each relation explicitly via the relation method instead.
        if (! $episode->relationLoaded('season')) {
            $episode->setRelation('season', $episode->season()->first());
        }
        $seasonRel = $episode->getRelation('season');
        if ($seasonRel instanceof Season && ! $seasonRel->relationLoaded('series')) {
            $seasonRel->setRelation('series', $seasonRel->series()->first());
        }
        if (! $episode->relationLoaded('series')) {
            $episode->setRelation('series', $episode->series()->first());
        }

        $syncLocation = rtrim($syncSettings['sync_location'] ?? '', '/');
        $pathStructure = $syncSettings['path_structure'] ?? ['category', 'series', 'season'];
        $replaceChar = $syncSettings['replace_char'] ?? 'space';
        $cleanSpecialChars = $syncSettings['clean_special_chars'] ?? false;
        $tmdbIdFormat = $syncSettings['tmdb_id_format'] ?? 'square';
        $filenameMetadata = $syncSettings['filename_metadata'] ?? [];
        $tmdbIdApplyTo = $syncSettings['tmdb_id_apply_to'] ?? 'episodes';

        $path = $syncLocation;
        $series = $episode->season?->series ?? $episode->series;

        if (in_array('category', $pathStructure)) {
            $category = $series?->category;
            $catName = $category?->name ?? $category?->name_internal ?? 'Uncategorized';
            $cleanName = $cleanSpecialChars
                ? PlaylistService::makeFilesystemSafe($catName, $replaceChar)
                : PlaylistService::makeFilesystemSafe($catName);
            $path .= '/'.$cleanName;
        }

        if (in_array('series', $pathStructure)) {
            $seriesFolder = $this->serieFileNameService->generateSerieFolderName($series ?? new Series(['name' => 'Unknown']));

            if (! empty($series?->release_date)) {
                $year = substr($series->release_date, 0, 4);
                if (strpos($seriesFolder, "({$year})") === false) {
                    $seriesFolder .= " ({$year})";
                }
            }

            $tmdbEnabled = in_array('tmdb_id', $filenameMetadata, true);
            $applyTmdbToSeriesFolder = $tmdbEnabled && in_array($tmdbIdApplyTo, ['series', 'both'], true);

            if ($applyTmdbToSeriesFolder && $series) {
                $ids = $series->getMovieDbIds();
                $tmdbId = $ids['tmdb'] ?? null;
                $tvdbId = $ids['tvdb'] ?? null;
                $imdbId = $ids['imdb'] ?? null;

                $bracket = $tmdbIdFormat === 'curly' ? ['{', '}'] : ['[', ']'];
                if (! empty($tmdbId)) {
                    $seriesFolder .= " {$bracket[0]}tmdb-{$tmdbId}{$bracket[1]}";
                } elseif (! empty($tvdbId)) {
                    $seriesFolder .= " {$bracket[0]}tvdb-{$tvdbId}{$bracket[1]}";
                } elseif (! empty($imdbId)) {
                    $seriesFolder .= " {$bracket[0]}imdb-{$imdbId}{$bracket[1]}";
                }
            }

            $cleanName = $cleanSpecialChars
                ? PlaylistService::makeFilesystemSafe($seriesFolder, $replaceChar)
                : PlaylistService::makeFilesystemSafe($seriesFolder);
            $path .= '/'.$cleanName;
        }

        if (in_array('season', $pathStructure)) {
            $season = $episode->getRelation('season');
            if ($season instanceof Season) {
                $seasonFolder = $this->serieFileNameService->generateSeasonFolderName($season);
            } else {
                $seasonFolder = 'Season '.str_pad((string) ($episode->season ?? 0), 2, '0', STR_PAD_LEFT);
            }
            $path .= '/'.$seasonFolder;
        }

        $fileName = $this->serieFileNameService->generateEpisodeFileName($episode, $setting);

        return $path.'/'.$fileName.'.strm';
    }
}
