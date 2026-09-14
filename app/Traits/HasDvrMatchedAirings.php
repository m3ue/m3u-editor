<?php

namespace App\Traits;

use App\Enums\DvrMatchMode;
use App\Enums\DvrSeriesMode;
use App\Models\Channel;
use App\Models\DvrRecordingRule;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Services\DvrSchedulerService;
use App\Settings\GeneralSettings;
use App\Support\SeriesKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

trait HasDvrMatchedAirings
{
    /**
     * Query EpgProgramme entries for the next 14 days matching the given series rule.
     * Applies the same match_mode / series_mode logic as DvrSchedulerService::matchSeriesRule(),
     * and honours include_disabled_channels from the rule's DvrSetting.
     *
     * @return list<array<string, mixed>>
     */
    protected static function resolveMatchedAirings(?DvrRecordingRule $rule): array
    {
        if (! $rule || ! $rule->series_title) {
            return [];
        }

        $dvrSetting = $rule->dvrSetting;
        if (! $dvrSetting) {
            return [];
        }

        $epgChannelIds = static::resolveEpgScopeForRule($rule);

        if (empty($epgChannelIds)) {
            return [];
        }

        $userId = $dvrSetting->user_id;

        $query = EpgProgramme::query()
            ->whereIn('epg_channel_id', $epgChannelIds)
            ->where('start_time', '>=', now())
            ->where('start_time', '<=', now()->addDays(14))
            ->whereHas('epg', fn (Builder $q) => $q->where('user_id', $userId))
            ->orderBy('start_time');

        $title = $rule->series_title;
        $matchMode = $rule->match_mode ?? DvrMatchMode::Contains;

        if ($matchMode === DvrMatchMode::Tmdb) {
            if (empty($rule->tmdb_id)) {
                return [];
            }

            $query->where('tmdb_id', $rule->tmdb_id);
        } else {
            // Escape LIKE metacharacters in the user-entered title — see the
            // same treatment in DvrSchedulerService::matchSeriesRule().
            $escapedTitle = addcslashes($title, '\\%_');

            [$sql, $binding] = match ($matchMode) {
                DvrMatchMode::Exact => ['lower(title) = lower(?)', $title],
                DvrMatchMode::StartsWith => ["lower(title) LIKE lower(?) ESCAPE '\\'", $escapedTitle.'%'],
                default => ["lower(title) LIKE lower(?) ESCAPE '\\'", '%'.$escapedTitle.'%'],
            };

            $query->whereRaw($sql, [$binding]);
        }

        if ($rule->series_mode === DvrSeriesMode::NewFlag) {
            $query->where('is_new', true);
        }

        $programmes = $query->get();

        if ($programmes->isEmpty()) {
            return [];
        }

        // Run the actual scheduler logic in dry-run mode to determine
        // which airings will be recorded vs skipped. This ensures
        // consistency between the preview and actual scheduling.
        $scheduler = app(DvrSchedulerService::class);
        $scheduledIds = $scheduler->matchSeriesRuleDryRun($rule, 14 * 24 * 60);
        Log::debug('DVR: resolveMatchedAirings dry run result', [
            'scheduled_count' => count($scheduledIds['scheduled'] ?? []),
            'skipped_count' => count($scheduledIds['skipped'] ?? []),
        ]);

        $channelIds = $programmes->pluck('epg_channel_id')->unique()->filter()->values()->all();
        $channelNames = static::resolveAiringChannelNames($channelIds);
        $timezone = config('dev.timezone') ?? app(GeneralSettings::class)->app_timezone ?? 'UTC';

        return $programmes->map(function (EpgProgramme $p) use ($channelNames, $timezone, $rule, $scheduledIds) {
            [$season, $episode, $subtitle, $description] = static::parseAiringSeasonEpisode($p);

            $scheduledKeys = $scheduledIds['scheduled_keys'] ?? [];
            $skipReason = null;
            $willRecord = in_array($p->id, $scheduledIds['scheduled'] ?? []);

            if (! $willRecord && in_array($p->id, $scheduledIds['skipped'] ?? [])) {
                $hasSeasonEpisode = $season !== null && $episode !== null;
                $seriesKey = $hasSeasonEpisode
                    ? SeriesKey::for($rule->dvrSetting->id, $rule->series_title)
                    : SeriesKey::for($rule->dvrSetting->id, $p->title);
                // Mirror the scheduler's dedup identity: S/E programmes key on
                // season|episode; sports (no S/E) key on the airing date
                // within the setting's dedup window — a same-title airing is
                // a replay of a recent game (skipped) but a re-match beyond
                // the window is a new event (recorded).
                $windowDays = $rule->sportsDedupDays();
                $lookupKey = ($seriesKey ?? '').'|'.($hasSeasonEpisode
                    ? ($season ?? '').'|'.($episode ?? '')
                    : ($p->start_time?->toDateString() ?? '').'|');
                $inWindowScheduled = $hasSeasonEpisode
                    ? in_array($lookupKey, $scheduledKeys)
                    : $rule->sportsAlreadyScheduled($seriesKey ?? '', $p->start_time, $windowDays, $scheduledKeys);

                // Check the actual recording status to differentiate
                $recordingStatus = $seriesKey !== null
                    ? ($hasSeasonEpisode
                        ? $rule->getEpisodeRecordingStatus($seriesKey, $season, $episode)
                        : $rule->getEpisodeRecordingStatusOnDate($seriesKey, $p->start_time, $windowDays))
                    : null;

                if ($inWindowScheduled) {
                    $skipReason = 'already_scheduled';
                } elseif (in_array($recordingStatus, ['scheduled', 'recording', 'post_processing'])) {
                    $skipReason = 'already_scheduled';
                } else {
                    $skipReason = 'already_recorded';
                }
            }

            return [
                'channel_name' => $channelNames[$p->epg_channel_id] ?? $p->epg_channel_id,
                'start_time_human' => $p->start_time?->timezone($timezone)->format('D M j, g:ia'),
                'season' => $season,
                'episode' => $episode,
                'subtitle' => $subtitle,
                'description' => $description,
                'is_new' => $p->is_new,
                'premiere' => $p->premiere,
                'will_record' => $willRecord,
                'skip_reason' => $skipReason,
            ];
        })->values()->all();
    }

    /**
     * Resolve the EPG channel string IDs in scope for a series rule, applying
     * include_disabled_channels from the rule's DvrSetting.
     *
     * Mirrors DvrSchedulerService::resolveSeriesEpgScope() with added channel
     * enabled/disabled filtering.
     *
     * @return list<string>
     */
    private static function resolveEpgScopeForRule(DvrRecordingRule $rule): array
    {
        $includeDisabled = $rule->dvrSetting->include_disabled_channels ?? false;

        if ($rule->epg_channel_id) {
            $stringId = $rule->epgChannel?->channel_id;

            return $stringId ? [$stringId] : [];
        }

        if ($rule->channel_id) {
            $channelQuery = Channel::where('id', $rule->channel_id)->with('epgChannel');

            if (! $includeDisabled) {
                $channelQuery->where('enabled', true);
            }

            $stringId = $channelQuery->first()?->epgChannel?->channel_id;

            return $stringId ? [$stringId] : [];
        }

        if ($rule->source_channel_id) {
            $channelQuery = Channel::where('id', $rule->source_channel_id)->with('epgChannel');

            if (! $includeDisabled) {
                $channelQuery->where('enabled', true);
            }

            $stringId = $channelQuery->first()?->epgChannel?->channel_id;

            return $stringId ? [$stringId] : [];
        }

        $channelQuery = $rule->dvrSetting->ownerChannels();

        if (! $channelQuery) {
            return [];
        }

        $channelQuery->whereNotNull('channels.epg_channel_id')
            ->with('epgChannel');

        if (! $includeDisabled) {
            $channelQuery->where('channels.enabled', true);
        }

        return $channelQuery->get()
            ->map(fn (Channel $c) => $c->epgChannel?->channel_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $channelIds
     * @return array<string, string>
     */
    private static function resolveAiringChannelNames(array $channelIds): array
    {
        if (empty($channelIds)) {
            return [];
        }

        return EpgChannel::without('epg')
            ->whereIn('channel_id', $channelIds)
            ->get(['channel_id', 'name', 'display_name', 'name_custom', 'display_name_custom'])
            ->mapWithKeys(fn (EpgChannel $c) => [
                $c->channel_id => $c->name_custom
                    ?: $c->display_name_custom
                    ?: $c->display_name
                    ?: $c->name,
            ])
            ->all();
    }

    /**
     * @return array{0: int|null, 1: int|null, 2: string|null, 3: string|null}
     */
    private static function parseAiringSeasonEpisode(EpgProgramme $p): array
    {
        $season = $p->season;
        $episode = $p->episode;
        $subtitle = $p->subtitle;
        $description = $p->description;

        if (empty($subtitle) && empty($season) && empty($episode) && ! empty($description)) {
            $lines = explode("\n", $description, 2);
            $firstLine = trim($lines[0]);

            if (preg_match('/^S(\d+)\s+E(\d+)\s+(.+)$/i', $firstLine, $matches)) {
                $season = (int) $matches[1];
                $episode = (int) $matches[2];
                $subtitle = trim($matches[3]);
                $description = isset($lines[1]) ? trim($lines[1]) : '';
            }
        }

        return [$season, $episode, $subtitle, $description];
    }
}
