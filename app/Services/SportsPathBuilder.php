<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\Series;
use Carbon\Carbon;

class SportsPathBuilder
{
    public function resolveLeagueForChannel(Channel $channel, array $settings): string
    {
        $source = $settings['sports_league_source'] ?? 'group';
        $groupModel = $channel->relationLoaded('group')
            ? $channel->getRelation('group')
            : null;
        $groupName = $groupModel instanceof Group
            ? (string) ($groupModel->name ?? $groupModel->name_internal ?? '')
            : '';
        $channelGroupName = is_string($channel->group) ? $channel->group : '';
        $resolvedGroupName = trim($channelGroupName) !== '' ? $channelGroupName : $groupName;

        return match ($source) {
            'category' => (string) ($resolvedGroupName !== '' ? $resolvedGroupName : 'Sports'),
            'vod_title' => (string) ($channel->title_custom ?? $channel->title ?? 'Sports'),
            'static' => (string) ($settings['sports_static_league'] ?? 'Sports'),
            default => (string) ($resolvedGroupName !== '' ? $resolvedGroupName : 'Sports'),
        };
    }

    public function resolveLeagueForSeries(Series $series, array $settings): string
    {
        $source = $settings['sports_league_source'] ?? 'series_name';

        return match ($source) {
            'category' => (string) ($series->category?->name ?? 'Sports'),
            'static' => (string) ($settings['sports_static_league'] ?? 'Sports'),
            default => (string) ($series->name ?? 'Sports'),
        };
    }

    public function resolveSeasonYearForChannel(Channel $channel, array $settings): int
    {
        $source = $settings['sports_season_source'] ?? 'title_year';

        if ($source === 'release_date') {
            $fromDate = $this->extractYear((string) ($channel->year ?? ''));
            if ($fromDate !== null) {
                return $fromDate;
            }

            $infoDate = $channel->info['releasedate'] ?? $channel->info['release_date'] ?? $channel->info['air_date'] ?? null;
            $fromInfo = $this->extractYear((string) $infoDate);
            if ($fromInfo !== null) {
                return $fromInfo;
            }
        }

        if ($source === 'current_year') {
            return (int) now()->year;
        }

        $title = (string) ($channel->title_custom ?? $channel->title ?? '');
        $fromTitle = $this->extractYear($title);
        if ($fromTitle !== null) {
            return $fromTitle;
        }

        $fromYearField = $this->extractYear((string) ($channel->year ?? ''));

        return $fromYearField ?? (int) now()->year;
    }

    public function resolveSeasonYearForEpisode(Episode $episode, Series $series, array $settings): int
    {
        $source = $settings['sports_season_source'] ?? 'title_year';

        if ($source === 'release_date') {
            $airDate = $episode->info['air_date'] ?? $episode->info['releasedate'] ?? null;
            $fromAirDate = $this->extractYear((string) $airDate);
            if ($fromAirDate !== null) {
                return $fromAirDate;
            }

            $fromSeriesDate = $this->extractYear((string) ($series->release_date ?? ''));
            if ($fromSeriesDate !== null) {
                return $fromSeriesDate;
            }
        }

        if ($source === 'current_year') {
            return (int) now()->year;
        }

        $fromTitle = $this->extractYear((string) ($episode->title ?? ''));
        if ($fromTitle !== null) {
            return $fromTitle;
        }

        $fromSeriesTitle = $this->extractYear((string) ($series->name ?? ''));
        if ($fromSeriesTitle !== null) {
            return $fromSeriesTitle;
        }

        $fromSeriesDate = $this->extractYear((string) ($series->release_date ?? ''));

        return $fromSeriesDate ?? (int) now()->year;
    }

    public function resolveEventDateForChannel(Channel $channel): ?Carbon
    {
        $date = $channel->info['releasedate'] ?? $channel->info['release_date'] ?? $channel->info['air_date'] ?? null;

        return $this->toCarbon($date);
    }

    public function resolveEventDateForEpisode(Episode $episode): ?Carbon
    {
        $date = $episode->info['air_date'] ?? $episode->info['releasedate'] ?? null;

        return $this->toCarbon($date);
    }

    public function buildEpisodeCode(int $seasonYear, int $sequence, string $strategy = 'sequential_per_season', ?Carbon $eventDate = null, ?int $episodeNumber = null): string
    {
        if ($strategy === 'from_episode_field' && $episodeNumber !== null) {
            return sprintf('S%dE%02d', $seasonYear, max(1, $episodeNumber));
        }

        if ($strategy === 'date_code') {
            $dateCode = ($eventDate ?? now())->format('Ymd');

            return sprintf('S%dE%s%02d', $seasonYear, $dateCode, max(1, $sequence));
        }

        return sprintf('S%dE%02d', $seasonYear, max(1, $sequence));
    }

    public function extractYear(string $value): ?int
    {
        if (preg_match('/\b(19|20)\d{2}\b/', $value, $matches) === 1) {
            return (int) $matches[0];
        }

        return null;
    }

    private function toCarbon(mixed $date): ?Carbon
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Throwable) {
            return null;
        }
    }
}
