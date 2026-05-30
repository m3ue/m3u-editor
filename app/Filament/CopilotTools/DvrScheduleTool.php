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
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Copilot tool for searching EPG programmes and scheduling DVR recordings.
 *
 * Supports six actions:
 * - "now_playing": What is currently airing on a specific channel
 * - "search": Find upcoming programmes by title/keyword, optionally filtered by channel
 * - "channel_schedule": Full programme schedule for a channel (upcoming + currently airing)
 * - "schedule_once": Record a specific programme once
 * - "schedule_series": Create a series recording rule
 * - "delete_rule": Delete a recording rule
 * - "remind": Create a one-shot recording (as a reminder)
 */
class DvrScheduleTool extends BaseTool
{
    private const VALID_ACTIONS = ['now_playing', 'search', 'channel_schedule', 'schedule_once', 'schedule_series', 'delete_rule', 'remind'];

    public function description(): Stringable|string
    {
        return 'Search EPG programme guide — find currently playing or upcoming TV shows by channel name or title, and schedule DVR recordings. Valid actions: "now_playing" (what\'s on right now on a channel), "search" (upcoming programmes by keyword, optionally filtered by channel), "channel_schedule" (full schedule for a channel), "schedule_once" (record a specific programme), "schedule_series" (record a series), "delete_rule" (delete a recording rule), "remind" (create a one-shot recording as a reminder).';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description(__('The action to perform: now_playing, search, channel_schedule, schedule_once, schedule_series, delete_rule, or remind')),
            'query' => $schema->string()
                ->description(__('Search keyword to find programmes by title/description (required for search action)')),
            'channel' => $schema->string()
                ->description(__('Channel name to filter by (optional for search, required for now_playing and channel_schedule)')),
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
            return 'Missing required parameter: action. Use "now_playing", "search", "channel_schedule", "schedule_once", "schedule_series", "delete_rule", or "remind".';
        }

        return match ($action) {
            'now_playing' => $this->nowPlaying($request),
            'search' => $this->search($request),
            'channel_schedule' => $this->channelSchedule($request),
            'schedule_once' => $this->scheduleOnce($request),
            'schedule_series' => $this->scheduleSeries($request),
            'delete_rule' => $this->deleteRule($request),
            'remind' => $this->remind($request),
            default => "Unknown action: {$action}. Use \"now_playing\", \"search\", \"channel_schedule\", \"schedule_once\", \"schedule_series\", \"delete_rule\", or \"remind\".",
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

    /** Search for upcoming programmes matching the query, optionally filtered by channel. */
    private function search(Request $request): string
    {
        $query = trim((string) ($request['query'] ?? ''));
        $channel = trim((string) ($request['channel'] ?? ''));

        if ($query === '' && $channel === '') {
            return 'Missing required parameter: provide either query or channel for search action.';
        }

        $userId = auth()->id();
        $now = now();
        $weekFromNow = $now->copy()->addDays(7);

        if ($userId === null) {
            return 'You must be logged in to search programmes.';
        }

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
            ->where('epg_programmes.start_time', '>=', $now)
            ->where('epg_programmes.start_time', '<=', $weekFromNow);

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

            return "No upcoming programmes found matching '{$query}'{$filter}.";
        }

        $filterLabel = $channel !== '' ? " on {$channel}" : '';
        $lines = ["Upcoming programmes matching '{$query}'{$filterLabel} (next 7 days):", ''];

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
        $lines[] = 'Call schedule_once with a programme_id to record, or remind to set a reminder.';

        return implode("\n", $lines);
    }

    /** Full programme schedule for a channel (currently airing + upcoming). */
    private function channelSchedule(Request $request): string
    {
        $channel = trim((string) ($request['channel'] ?? ''));

        if ($channel === '') {
            return 'Missing required parameter: channel. Provide a channel name for channel_schedule action.';
        }

        $userId = auth()->id();
        $now = now();
        $weekFromNow = $now->copy()->addDays(7);

        if ($userId === null) {
            return 'You must be logged in to view a channel schedule.';
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
            ->where('epg_programmes.end_time', '>=', $now)
            ->where('epg_programmes.start_time', '<=', $weekFromNow)
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
            return "No programmes found for '{$channel}' in the next 7 days.";
        }

        $lines = ["Schedule for {$channel} (upcoming 7 days):", ''];

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
            $channel = Channel::where('id', $channelId)
                ->where('user_id', auth()->id())
                ->where('playlist_id', $dvrSetting->playlist_id)
                ->first();

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
            ->with('playlist')
            ->get();

        if ($settings->isEmpty()) {
            return 'No DVR settings found. Please create a DVR setting first.';
        }

        $lines = ['Available DVR settings:', ''];

        foreach ($settings as $setting) {
            $playlistName = $setting->playlist?->name ?? "DVR Setting #{$setting->id}";
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
}
