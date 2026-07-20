<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use App\Enums\DvrRuleType;
use App\Enums\DvrSeriesMode;
use App\Jobs\DvrSchedulerTick;
use App\Models\Channel;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use Carbon\CarbonInterface;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Copilot tool for browsing the EPG TV guide and scheduling DVR recordings.
 *
 * Use this tool for any question about what is on TV on the user's mapped
 * channels — currently playing, upcoming by keyword, full channel schedules,
 * or programmes airing around a specific show at a specific time. It can also
 * create, list, and delete DVR recording rules.
 *
 * Actions:
 * - "now_playing": What is currently airing on a specific channel
 * - "search": Find upcoming programmes by title/keyword, optionally filtered by channel and time window
 * - "around": Programmes airing before/after a specific show on a channel
 *             (e.g. "what's on WE TV around Love After Lockup later today")
 * - "channel_schedule": Full programme schedule for a channel within a time window
 * - "schedule_once": Record a specific programme once
 * - "schedule_series": Create a series recording rule
 * - "delete_rule": Delete a recording rule
 * - "remind": Create a one-shot recording (as a reminder)
 */
class DvrScheduleTool extends BaseTool
{
    private const VALID_ACTIONS = ['now_playing', 'search', 'around', 'channel_schedule', 'schedule_once', 'schedule_series', 'delete_rule', 'remind'];

    private const TIME_WINDOWS = ['today', 'tomorrow', 'this_week'];

    public function description(): Stringable|string
    {
        return 'Browse the live EPG TV guide and schedule DVR recordings. Use this tool for any question about what is on TV — what is on right now, what is on later today/tomorrow/this week, what is on around a specific show, or full channel schedules. Time-window filters ("today", "tomorrow", "this_week") keep results focused. Can also create, list, and delete DVR recording rules. Valid actions: "now_playing" (what is on right now on a channel), "search" (upcoming programmes by title/keyword, optionally filtered by channel and time window), "around" (programmes airing before and after a specific show on a channel — the right tool for "what is on WE TV around Love After Lockup later today"), "channel_schedule" (full schedule for a channel), "schedule_once" (record a specific programme), "schedule_series" (record a series), "delete_rule" (delete a recording rule), "remind" (create a one-shot recording as a reminder).';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description(__('The action to perform: now_playing, search, around, channel_schedule, schedule_once, schedule_series, delete_rule, or remind')),
            'query' => $schema->string()
                ->description(__('Search keyword to find programmes by title/description (required for search and around actions)')),
            'channel' => $schema->string()
                ->description(__('Channel name to filter by (required for now_playing, around, and channel_schedule; optional for search). Matches display_name or name of an EPG channel the user has mapped to a Channel.')),
            'time_window' => $schema->string()
                ->description(__('Time window for search/around/channel_schedule results. One of: "today" (now until midnight), "tomorrow" (full next day), "this_week" (next 7 days, default).')),
            'airing_time' => $schema->string()
                ->description(__('Optional ISO 8601 datetime anchor for the around action. If provided, the matched programme is the one starting nearest to this time. (optional for around)')),
            'context_before' => $schema->integer()
                ->description(__('For the around action: number of programmes to show BEFORE the matched show on the same channel. Default: 2.')),
            'context_after' => $schema->integer()
                ->description(__('For the around action: number of programmes to show AFTER the matched show on the same channel. Default: 3.')),
            'programme_id' => $schema->integer()
                ->description(__('The EpgProgramme ID to record once (required for schedule_once and remind actions)')),
            'title' => $schema->string()
                ->description(__('Series title for the recording rule (required for schedule_series action)')),
            'rule_id' => $schema->integer()
                ->description(__('The DvrRecordingRule ID to delete (required for delete_rule action)')),
            'dvr_setting_id' => $schema->integer()
                ->description(__('The DVR setting ID to use. Omit to see a list of available settings.')),
            'series_mode' => $schema->string()
                ->description(__('Series mode: all, new_only, unique_se. Default: all. (optional for schedule_series)')),
            'channel_id' => $schema->integer()
                ->description(__('Specific channel ID to pin the series rule to. Omit for "any channel". (optional for schedule_series)')),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $action = strtolower(trim((string) ($request['action'] ?? '')));

        if ($action === '') {
            return 'Missing required parameter: action. Use "now_playing", "search", "around", "channel_schedule", "schedule_once", "schedule_series", "delete_rule", or "remind".';
        }

        return match ($action) {
            'now_playing' => $this->nowPlaying($request),
            'search' => $this->search($request),
            'around' => $this->around($request),
            'channel_schedule' => $this->channelSchedule($request),
            'schedule_once' => $this->scheduleOnce($request),
            'schedule_series' => $this->scheduleSeries($request),
            'delete_rule' => $this->deleteRule($request),
            'remind' => $this->remind($request),
            default => "Unknown action: {$action}. Use \"now_playing\", \"search\", \"around\", \"channel_schedule\", \"schedule_once\", \"schedule_series\", \"delete_rule\", or \"remind\".",
        };
    }

    /** Find programmes currently airing on a specific channel. */
    private function nowPlaying(Request $request): string
    {
        $channel = trim((string) ($request['channel'] ?? ''));

        if ($channel === '') {
            return 'Missing required parameter: channel. Provide a channel name for now_playing action.';
        }

        $userId = auth()->id();
        $now = now();

        if ($userId === null) {
            return 'You must be logged in to check what\'s currently playing.';
        }

        $channelId = $this->resolveChannelIdForUser($channel, $userId);

        if ($channelId === null) {
            return "No channel found matching '{$channel}' in your playlists.";
        }

        $programmes = EpgProgramme::query()
            ->join('epg_channels', 'epg_channels.channel_id', '=', 'epg_programmes.epg_channel_id')
            ->join('channels', 'channels.epg_channel_id', '=', 'epg_channels.id')
            ->where('channels.user_id', $userId)
            ->where('channels.id', $channelId)
            ->where('epg_programmes.start_time', '<=', $now)
            ->where('epg_programmes.end_time', '>=', $now)
            ->orderBy('epg_programmes.start_time', 'asc')
            ->select([
                'epg_programmes.id',
                'epg_programmes.title',
                'epg_programmes.start_time',
                'epg_programmes.end_time',
                'epg_programmes.season',
                'epg_programmes.episode',
                'epg_channels.display_name as channel_display_name',
            ])
            ->get();

        if ($programmes->isEmpty()) {
            return "Nothing is currently airing on '{$channel}'.";
        }

        $lines = ["Currently playing on {$channel}:", ''];

        foreach ($programmes as $programme) {
            $start = $programme->start_time->format('H:i');
            $end = $programme->end_time->format('H:i');
            $episodeInfo = '';

            if ($programme->season !== null && $programme->episode !== null) {
                $episodeInfo = sprintf(' S%02dE%02d', $programme->season, $programme->episode);
            }

            $lines[] = "  #{$programme->id} {$programme->title}{$episodeInfo} | {$start} → {$end}";

        }

        $lines[] = '';
        $lines[] = 'Call schedule_once with a programme_id to record, or remind to set a reminder.';

        return implode("\n", $lines);
    }

    /** Search for upcoming programmes matching the query, optionally filtered by channel and time window. */
    private function search(Request $request): string
    {
        $query = trim((string) ($request['query'] ?? ''));
        $channel = trim((string) ($request['channel'] ?? ''));
        $timeWindow = $this->normalizeTimeWindow($request['time_window'] ?? null);

        if ($query === '' && $channel === '') {
            return 'Missing required parameter: provide either query or channel for search action.';
        }

        $userId = auth()->id();

        if ($userId === null) {
            return 'You must be logged in to search programmes.';
        }

        [$windowStart, $windowEnd] = $this->resolveWindowBounds($timeWindow);

        $dvrSettingIdRaw = $request['dvr_setting_id'] ?? null;
        $dvrSettingId = ($dvrSettingIdRaw !== null && (int) $dvrSettingIdRaw > 0) ? (int) $dvrSettingIdRaw : null;
        $includeDisabled = false;

        if ($dvrSettingId !== null) {
            $setting = DvrSetting::where('id', $dvrSettingId)
                ->where('user_id', $userId)
                ->first();
            $includeDisabled = $setting?->include_disabled_channels ?? false;
        }

        $programmesBuilder = EpgProgramme::query()
            ->join('epg_channels', 'epg_channels.channel_id', '=', 'epg_programmes.epg_channel_id')
            ->join('channels', 'channels.epg_channel_id', '=', 'epg_channels.id')
            ->where('channels.user_id', $userId)
            ->where('epg_programmes.start_time', '>=', $windowStart)
            ->where('epg_programmes.start_time', '<=', $windowEnd);

        if ($channel !== '') {
            $channelId = $this->resolveChannelIdForUser($channel, $userId);
            if ($channelId !== null) {
                $programmesBuilder->where('channels.id', $channelId);
            }
        }

        if ($query !== '') {
            $programmesBuilder->where(function ($q) use ($query): void {
                $q->where('epg_programmes.title', 'like', "%{$query}%")
                    ->orWhere('epg_programmes.description', 'like', "%{$query}%");
            });
        }

        if (! $includeDisabled) {
            $programmesBuilder->where('channels.enabled', true);
        }

        $programmes = $programmesBuilder
            ->orderBy('epg_programmes.start_time', 'asc')
            ->limit(15)
            ->select([
                'epg_programmes.id',
                'epg_programmes.title',
                'epg_programmes.start_time',
                'epg_programmes.end_time',
                'epg_programmes.season',
                'epg_programmes.episode',
                'epg_channels.display_name as channel_display_name',
            ])
            ->get();

        if ($programmes->isEmpty()) {
            $filter = $channel !== '' ? " on '{$channel}'" : '';
            $windowLabel = $this->describeTimeWindow($timeWindow);

            return "No upcoming programmes found matching '{$query}'{$filter} {$windowLabel}.";
        }

        $filterLabel = $channel !== '' ? " on {$channel}" : '';
        $windowLabel = $this->describeTimeWindow($timeWindow);
        $lines = ["Upcoming programmes matching '{$query}'{$filterLabel} ({$windowLabel}):", ''];

        foreach ($programmes as $programme) {
            $start = $programme->start_time->format('Y-m-d H:i');
            $end = $programme->end_time->format('H:i');
            $channelName = $programme->channel_display_name ?? 'Unknown';
            $episodeInfo = '';

            if ($programme->season !== null && $programme->episode !== null) {
                $episodeInfo = sprintf(' S%02dE%02d', $programme->season, $programme->episode);
            }

            $lines[] = "  #{$programme->id} {$programme->title}{$episodeInfo} | {$channelName} | {$start} → {$end}";

        }

        $lines[] = '';
        $lines[] = 'Call schedule_once with a programme_id to record, or remind to set a reminder. Use the around action to see what is airing before and after a specific show on a channel.';

        return implode("\n", $lines);
    }

    /**
     * Find programmes airing before and after a specific show on a channel.
     *
     * Right tool for queries like "what is on WE TV around Love After Lockup later
     * today" — locates the target programme by title on the given channel, then
     * returns a slice of the channel's schedule centered on it.
     */
    private function around(Request $request): string
    {
        $query = trim((string) ($request['query'] ?? ''));
        $channel = trim((string) ($request['channel'] ?? ''));
        $timeWindow = $this->normalizeTimeWindow($request['time_window'] ?? null);
        $airingTimeRaw = trim((string) ($request['airing_time'] ?? ''));
        $contextBefore = max(0, min(20, (int) ($request['context_before'] ?? 2)));
        $contextAfter = max(0, min(20, (int) ($request['context_after'] ?? 3)));

        if ($query === '') {
            return 'Missing required parameter: query (required for around action). Provide the title or keyword of the show to anchor on.';
        }

        if ($channel === '') {
            return 'Missing required parameter: channel (required for around action). Provide the channel name (e.g. "WE TV").';
        }

        $userId = auth()->id();

        if ($userId === null) {
            return 'You must be logged in to look up programmes around a show.';
        }

        $channelId = $this->resolveChannelIdForUser($channel, $userId);

        if ($channelId === null) {
            return "No channel found matching '{$channel}' in your playlists.";
        }

        $airingTime = null;
        if ($airingTimeRaw !== '') {
            try {
                $airingTime = Carbon::parse($airingTimeRaw);
            } catch (\Throwable) {
                return "Could not parse airing_time '{$airingTimeRaw}'. Use ISO 8601 format like 2026-06-05T20:00:00Z.";
            }
        }

        [$windowStart, $windowEnd] = $this->resolveWindowBounds($timeWindow, $airingTime);

        $programmes = EpgProgramme::query()
            ->join('epg_channels', 'epg_channels.channel_id', '=', 'epg_programmes.epg_channel_id')
            ->join('channels', 'channels.epg_channel_id', '=', 'epg_channels.id')
            ->where('channels.user_id', $userId)
            ->where('channels.id', $channelId)
            ->where('channels.enabled', true)
            ->where('epg_programmes.end_time', '>=', $windowStart)
            ->where('epg_programmes.start_time', '<=', $windowEnd)
            ->orderBy('epg_programmes.start_time', 'asc')
            ->select([
                'epg_programmes.id',
                'epg_programmes.title',
                'epg_programmes.start_time',
                'epg_programmes.end_time',
                'epg_programmes.season',
                'epg_programmes.episode',
                'epg_programmes.is_new',
            ])
            ->get();

        if ($programmes->isEmpty()) {
            $windowLabel = $this->describeTimeWindow($timeWindow, $airingTime);

            return "No programmes found for '{$channel}' {$windowLabel}.";
        }

        $matchedIndex = $this->locateMatchedProgramme($programmes, $query, $airingTime);

        if ($matchedIndex === null) {
            $windowLabel = $this->describeTimeWindow($timeWindow, $airingTime);

            return "Found programmes for '{$channel}' {$windowLabel}, but none match '{$query}'. Try a broader search with action=search.";
        }

        $startIdx = max(0, $matchedIndex - $contextBefore);
        $endIdx = min($programmes->count() - 1, $matchedIndex + $contextAfter);
        $slice = $programmes->slice($startIdx, $endIdx - $startIdx + 1)->values();

        $lines = ["Programmes around '{$query}' on {$channel}:", ''];

        $now = now();
        foreach ($slice as $offset => $programme) {
            $globalIdx = $startIdx + $offset;
            $isMatch = $globalIdx === $matchedIndex;
            $marker = $isMatch ? '▶' : ' ';
            $start = $programme->start_time->format('Y-m-d H:i');
            $end = $programme->end_time->format('H:i');
            $episodeInfo = '';
            $newMarker = $programme->is_new ? ' [NEW]' : '';

            if ($programme->season !== null && $programme->episode !== null) {
                $episodeInfo = sprintf(' S%02dE%02d', $programme->season, $programme->episode);
            }

            $liveIndicator = $programme->start_time <= $now && $programme->end_time >= $now ? ' (on now)' : '';

            $lines[] = "  {$marker} #{$programme->id} {$programme->title}{$episodeInfo}{$newMarker}{$liveIndicator} | {$start} → {$end}";
        }

        $lines[] = '';
        $lines[] = 'Call schedule_once with a programme_id to record, or remind to set a reminder. The ▶ marker shows the matched programme.';

        return implode("\n", $lines);
    }

    /**
     * Locate the best-matching programme in the collection.
     *
     * If $airingTime is set, prefer the title/description match whose start_time
     * is nearest to it. Otherwise return the first title/description match.
     *
     * @param  Collection<int, EpgProgramme>  $programmes
     */
    private function locateMatchedProgramme(Collection $programmes, string $query, ?CarbonInterface $airingTime): ?int
    {
        $lowerQuery = strtolower($query);
        $matchedIndex = null;

        if ($airingTime !== null) {
            $bestDelta = null;
            foreach ($programmes as $idx => $programme) {
                if (! $this->programmeMatchesQuery($programme, $lowerQuery)) {
                    continue;
                }
                $delta = abs($programme->start_time->diffInSeconds($airingTime, false));
                if ($bestDelta === null || $delta < $bestDelta) {
                    $bestDelta = $delta;
                    $matchedIndex = $idx;
                }
            }

            if ($matchedIndex !== null) {
                return $matchedIndex;
            }
        }

        foreach ($programmes as $idx => $programme) {
            if ($this->programmeMatchesQuery($programme, $lowerQuery)) {
                return $idx;
            }
        }

        return null;
    }

    private function programmeMatchesQuery(EpgProgramme $programme, string $lowerQuery): bool
    {
        return str_contains(strtolower($programme->title), $lowerQuery)
            || str_contains(strtolower((string) $programme->description), $lowerQuery);
    }

    /** Full programme schedule for a channel (currently airing + upcoming). */
    private function channelSchedule(Request $request): string
    {
        $channel = trim((string) ($request['channel'] ?? ''));
        $timeWindow = $this->normalizeTimeWindow($request['time_window'] ?? null);

        if ($channel === '') {
            return 'Missing required parameter: channel. Provide a channel name for channel_schedule action.';
        }

        $userId = auth()->id();
        $now = now();

        if ($userId === null) {
            return 'You must be logged in to view a channel schedule.';
        }

        $channelId = $this->resolveChannelIdForUser($channel, $userId);

        if ($channelId === null) {
            return "No channel found matching '{$channel}' in your playlists.";
        }

        [$windowStart, $windowEnd] = $this->resolveWindowBounds($timeWindow);

        $programmes = EpgProgramme::query()
            ->join('epg_channels', 'epg_channels.channel_id', '=', 'epg_programmes.epg_channel_id')
            ->join('channels', 'channels.epg_channel_id', '=', 'epg_channels.id')
            ->where('channels.user_id', $userId)
            ->where('channels.id', $channelId)
            ->where('epg_programmes.end_time', '>=', $windowStart)
            ->where('epg_programmes.start_time', '<=', $windowEnd)
            ->orderBy('epg_programmes.start_time', 'asc')
            ->select([
                'epg_programmes.id',
                'epg_programmes.title',
                'epg_programmes.start_time',
                'epg_programmes.end_time',
                'epg_programmes.season',
                'epg_programmes.episode',
                'epg_programmes.is_new',
            ])
            ->limit(30)
            ->get();

        if ($programmes->isEmpty()) {
            $windowLabel = $this->describeTimeWindow($timeWindow);

            return "No programmes found for '{$channel}' {$windowLabel}.";
        }

        $windowLabel = $this->describeTimeWindow($timeWindow);
        $lines = ["Schedule for {$channel} ({$windowLabel}):", ''];

        foreach ($programmes as $programme) {
            $start = $programme->start_time->format('Y-m-d H:i');
            $end = $programme->end_time->format('H:i');
            $episodeInfo = '';
            $newMarker = $programme->is_new ? ' [NEW]' : '';

            if ($programme->season !== null && $programme->episode !== null) {
                $episodeInfo = sprintf(' S%02dE%02d', $programme->season, $programme->episode);
            }

            $nowIndicator = $programme->start_time <= $now && $programme->end_time >= $now ? ' ▶' : '';
            $lines[] = "  #{$programme->id} {$programme->title}{$episodeInfo}{$newMarker}{$nowIndicator} | {$start} → {$end}";

        }

        $lines[] = '';
        $lines[] = 'Call schedule_once with a programme_id to record, or remind to set a reminder.';

        return implode("\n", $lines);
    }

    /** Schedule a one-off recording for a specific programme. */
    private function scheduleOnce(Request $request): string
    {
        $dvrSettingIdRaw = $request['dvr_setting_id'] ?? null;
        $dvrSettingId = ($dvrSettingIdRaw !== null && (int) $dvrSettingIdRaw > 0) ? (int) $dvrSettingIdRaw : null;
        $programmeIdRaw = $request['programme_id'] ?? null;
        $programmeId = ($programmeIdRaw !== null && (int) $programmeIdRaw > 0) ? (int) $programmeIdRaw : null;

        if ($programmeId === null) {
            return 'Missing required parameter: programme_id (required for schedule_once action).';
        }

        if ($dvrSettingId === null) {
            return $this->listDvrSettings();
        }

        $dvrSetting = DvrSetting::where('id', $dvrSettingId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $dvrSetting) {
            return "DVR setting #{$dvrSettingId} not found or does not belong to you.";
        }

        $programme = EpgProgramme::find($programmeId);

        if (! $programme) {
            return "Programme #{$programmeId} not found.";
        }

        if ($programme->start_time <= now()) {
            return "Programme #{$programmeId} has already started or finished. Cannot schedule a once recording for past programmes.";
        }

        $exists = DvrRecordingRule::where('user_id', auth()->id())
            ->where('dvr_setting_id', $dvrSettingId)
            ->where('type', DvrRuleType::Once)
            ->where('programme_id', $programmeId)
            ->exists();

        if ($exists) {
            return 'A Once rule for this programme already exists.';
        }

        $channel = null;
        if ($programme->epg_channel_id) {
            $epgChannelId = EpgChannel::where('channel_id', $programme->epg_channel_id)->value('id');
            if ($epgChannelId) {
                $channel = Channel::where('user_id', auth()->id())
                    ->where('epg_channel_id', $epgChannelId)
                    ->first();
            }
        }

        if ($channel !== null && ! $channel->enabled && ! $dvrSetting->include_disabled_channels) {
            $channelTitle = $channel->getAttribute('title') ?: "#{$channel->id}";

            return "Channel '{$channelTitle}' is disabled and your DVR setting excludes disabled channels. "
                .'Enable the channel or enable "Show Disabled Channels in Browse Shows" in your DVR settings to proceed.';
        }

        DvrRecordingRule::create([
            'user_id' => auth()->id(),
            'dvr_setting_id' => $dvrSettingId,
            'type' => DvrRuleType::Once,
            'programme_id' => $programmeId,
            'series_title' => $programme->title,
            'channel_id' => $channel?->id,
            'enabled' => true,
            'priority' => 50,
        ]);

        DvrSchedulerTick::dispatch();

        $scheduledTime = $programme->start_time->format('Y-m-d H:i');

        return "Once rule created for '{$programme->title}' scheduled at {$scheduledTime}.";
    }

    /** Create a series recording rule. */
    private function scheduleSeries(Request $request): string
    {
        $dvrSettingIdRaw = $request['dvr_setting_id'] ?? null;
        $dvrSettingId = ($dvrSettingIdRaw !== null && (int) $dvrSettingIdRaw > 0) ? (int) $dvrSettingIdRaw : null;
        $title = isset($request['title']) ? trim((string) $request['title']) : '';
        $seriesModeRaw = isset($request['series_mode']) ? strtolower(trim((string) $request['series_mode'])) : 'all';
        $channelIdRaw = $request['channel_id'] ?? null;
        $channelId = ($channelIdRaw !== null && (int) $channelIdRaw > 0) ? (int) $channelIdRaw : null;

        if ($title === '') {
            return 'Missing required parameter: title (required for schedule_series action).';
        }

        if ($dvrSettingId === null) {
            return $this->listDvrSettings();
        }

        $dvrSetting = DvrSetting::where('id', $dvrSettingId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $dvrSetting) {
            return "DVR setting #{$dvrSettingId} not found or does not belong to you.";
        }

        $exists = DvrRecordingRule::where('user_id', auth()->id())
            ->where('dvr_setting_id', $dvrSettingId)
            ->where('type', DvrRuleType::Series)
            ->where('series_title', $title)
            ->exists();

        if ($exists) {
            return "A Series rule for '{$title}' already exists.";
        }

        $seriesMode = match ($seriesModeRaw) {
            'new_only' => DvrSeriesMode::NewFlag,
            'unique_se' => DvrSeriesMode::UniqueSe,
            default => DvrSeriesMode::All,
        };

        if ($channelId !== null) {
            $subquery = $dvrSetting->ownerChannelsSubquery();
            $channel = $subquery
                ? Channel::where('id', $channelId)->where('user_id', auth()->id())->whereIn('id', $subquery)->first()
                : null;

            if (! $channel) {
                return "Channel #{$channelId} not found, does not belong to you, or is not in the DVR setting's playlist.";
            }

            if (! $channel->enabled && ! $dvrSetting->include_disabled_channels) {
                return "Channel #{$channelId} is disabled and your DVR setting excludes disabled channels. "
                    .'Enable the channel or enable "Show Disabled Channels in Browse Shows" in your DVR settings to proceed.';
            }
        }

        DvrRecordingRule::create([
            'user_id' => auth()->id(),
            'dvr_setting_id' => $dvrSettingId,
            'type' => DvrRuleType::Series,
            'series_title' => $title,
            'enabled' => true,
            'series_mode' => $seriesMode,
            'channel_id' => $channelId,
            'priority' => 50,
        ]);

        DvrSchedulerTick::dispatch();

        $modeLabel = $seriesMode->getLabel();
        $channelInfo = $channelId !== null ? " pinned to channel #{$channelId}" : ' (any channel)';

        return "Series rule created for '{$title}' ({$modeLabel}){$channelInfo}.";
    }

    /** Delete a recording rule. */
    private function deleteRule(Request $request): string
    {
        $ruleIdRaw = $request['rule_id'] ?? null;
        $ruleId = ($ruleIdRaw !== null && (int) $ruleIdRaw > 0) ? (int) $ruleIdRaw : null;

        if ($ruleId === null) {
            return 'Missing required parameter: rule_id (required for delete_rule action).';
        }

        $rule = DvrRecordingRule::where('id', $ruleId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $rule) {
            return "Recording rule #{$ruleId} not found or does not belong to you.";
        }

        $title = $rule->series_title ?? "Rule #{$ruleId}";

        $rule->delete();

        DvrSchedulerTick::dispatch();

        return "Recording rule '{$title}' (ID: {$ruleId}) has been deleted.";
    }

    /** Create a one-shot recording as a reminder (alias for schedule_once with different framing). */
    private function remind(Request $request): string
    {
        $dvrSettingIdRaw = $request['dvr_setting_id'] ?? null;
        $dvrSettingId = ($dvrSettingIdRaw !== null && (int) $dvrSettingIdRaw > 0) ? (int) $dvrSettingIdRaw : null;
        $programmeIdRaw = $request['programme_id'] ?? null;
        $programmeId = ($programmeIdRaw !== null && (int) $programmeIdRaw > 0) ? (int) $programmeIdRaw : null;

        if ($programmeId === null) {
            return 'Missing required parameter: programme_id (required for remind action). Use search or channel_schedule first to find a programme ID.';
        }

        if ($dvrSettingId === null) {
            return $this->listDvrSettings();
        }

        $dvrSetting = DvrSetting::where('id', $dvrSettingId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $dvrSetting) {
            return "DVR setting #{$dvrSettingId} not found or does not belong to you.";
        }

        $programme = EpgProgramme::find($programmeId);

        if (! $programme) {
            return "Programme #{$programmeId} not found.";
        }

        if ($programme->start_time <= now()) {
            return "Programme #{$programmeId} has already started or finished. Cannot remind for past programmes.";
        }

        $exists = DvrRecordingRule::where('user_id', auth()->id())
            ->where('dvr_setting_id', $dvrSettingId)
            ->where('type', DvrRuleType::Once)
            ->where('programme_id', $programmeId)
            ->exists();

        if ($exists) {
            $scheduledTime = $programme->start_time->format('Y-m-d H:i');

            return "A reminder for '{$programme->title}' already exists (scheduled at {$scheduledTime}).";
        }

        $channel = null;
        if ($programme->epg_channel_id) {
            $epgChannelId = EpgChannel::where('channel_id', $programme->epg_channel_id)->value('id');
            if ($epgChannelId) {
                $channel = Channel::where('user_id', auth()->id())
                    ->where('epg_channel_id', $epgChannelId)
                    ->first();
            }
        }

        DvrRecordingRule::create([
            'user_id' => auth()->id(),
            'dvr_setting_id' => $dvrSettingId,
            'type' => DvrRuleType::Once,
            'programme_id' => $programmeId,
            'series_title' => $programme->title,
            'channel_id' => $channel?->id,
            'enabled' => true,
            'priority' => 50,
        ]);

        DvrSchedulerTick::dispatch();

        $scheduledTime = $programme->start_time->format('Y-m-d H:i');
        $channelName = $channel?->name ?? 'Unknown channel';

        return "Reminder set for '{$programme->title}' on {$channelName} at {$scheduledTime}. You'll be able to watch or record it when it airs.";
    }

    /** List available DVR settings for the user. */
    private function listDvrSettings(): string
    {
        $settings = DvrSetting::where('user_id', auth()->id())
            ->with(['playlist', 'customPlaylist', 'mergedPlaylist'])
            ->get();

        if ($settings->isEmpty()) {
            return 'No DVR settings found. Please create a DVR setting first.';
        }

        $lines = ['Available DVR settings:', ''];

        foreach ($settings as $setting) {
            $playlistName = $setting->owner()?->name ?? "DVR Setting #{$setting->id}";
            $lines[] = "  #{$setting->id} {$playlistName}";
        }

        $lines[] = '';
        $lines[] = 'Pick one and call again with dvr_setting_id.';

        return implode("\n", $lines);
    }

    /**
     * Resolve a channel name to a channel ID for the given user.
     *
     * @return int|null Channel ID or null if not found
     */
    private function resolveChannelIdForUser(string $channelName, int $userId): ?int
    {
        return Channel::query()
            ->join('epg_channels', 'epg_channels.id', '=', 'channels.epg_channel_id')
            ->where('channels.user_id', $userId)
            ->where(function ($q) use ($channelName): void {
                $q->where('epg_channels.display_name', 'like', "%{$channelName}%")
                    ->orWhere('epg_channels.name', 'like', "%{$channelName}%");
            })
            ->value('channels.id');
    }

    /**
     * Normalize the time_window parameter to a known keyword.
     *
     * Unknown values fall back to "this_week" (preserves back-compat with the
     * 7-day default the tool used before the time_window parameter existed).
     */
    private function normalizeTimeWindow(mixed $raw): string
    {
        $value = is_string($raw) ? strtolower(trim($raw)) : '';

        return in_array($value, self::TIME_WINDOWS, true) ? $value : 'this_week';
    }

    /**
     * Compute the [start, end] bounds for a given time window.
     *
     * If $airingTime is provided (used by the around action), the window is
     * centered on that time (±6h) so the matched programme and its neighbours
     * are included regardless of which named window was selected.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveWindowBounds(string $timeWindow, ?CarbonInterface $airingTime = null): array
    {
        if ($airingTime !== null) {
            return [
                $airingTime->copy()->subHours(6),
                $airingTime->copy()->addHours(12),
            ];
        }

        $now = now();

        return match ($timeWindow) {
            'today' => [$now->copy(), $now->copy()->endOfDay()],
            'tomorrow' => [$now->copy()->addDay()->startOfDay(), $now->copy()->addDay()->endOfDay()],
            default => [$now->copy(), $now->copy()->addDays(7)],
        };
    }

    /**
     * Human-readable label for a time window — used in success and not-found
     * messages so the user can tell which window the results cover.
     */
    private function describeTimeWindow(string $timeWindow, ?CarbonInterface $airingTime = null): string
    {
        if ($airingTime !== null) {
            return "around {$airingTime->format('Y-m-d H:i')}";
        }

        return match ($timeWindow) {
            'today' => 'today',
            'tomorrow' => 'tomorrow',
            default => 'in the next 7 days',
        };
    }
}
