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
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Copilot tool for searching upcoming EPG programmes and scheduling recordings.
 *
 * Supports three actions:
 * - "search": Find upcoming programmes by title keyword
 * - "schedule_once": Record a specific programme once
 * - "schedule_series": Create a series recording rule
 */
class DvrScheduleTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search upcoming EPG programmes and schedule DVR recordings. Actions: "search" (find upcoming programmes by keyword), "schedule_once" (record a specific programme), "schedule_series" (create a series recording rule). Use search first to find programme IDs.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description(__('The action to perform: search, schedule_once, or schedule_series')),
            'query' => $schema->string()
                ->description(__('Search keyword to find programmes by title/description (required for search action)')),
            'programme_id' => $schema->integer()
                ->description(__('The EpgProgramme ID to record once (required for schedule_once action)')),
            'title' => $schema->string()
                ->description(__('Series title for the recording rule (required for schedule_series action)')),
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
            return 'Missing required parameter: action. Use "search", "schedule_once", or "schedule_series".';
        }

        return match ($action) {
            'search' => $this->search($request),
            'schedule_once' => $this->scheduleOnce($request),
            'schedule_series' => $this->scheduleSeries($request),
            default => "Unknown action: {$action}. Use \"search\", \"schedule_once\", or \"schedule_series\".",
        };
    }

    /** Search for upcoming programmes matching the query. */
    private function search(Request $request): string
    {
        $query = trim((string) ($request['query'] ?? ''));

        if ($query === '') {
            return 'Missing required parameter: query. Provide a title or keyword to search.';
        }

        $userId = auth()->id();
        $now = now();
        $weekFromNow = $now->copy()->addDays(7);

        // Determine whether to include disabled channels based on the setting
        $dvrSettingIdRaw = $request['dvr_setting_id'] ?? null;
        $dvrSettingId = ($dvrSettingIdRaw !== null && (int) $dvrSettingIdRaw > 0) ? (int) $dvrSettingIdRaw : null;
        $includeDisabled = false;

        if ($dvrSettingId !== null) {
            $setting = DvrSetting::where('id', $dvrSettingId)
                ->where('user_id', $userId)
                ->first();
            $includeDisabled = $setting?->include_disabled_channels ?? false;
        }

        // Join path: epg_programmes.epg_channel_id (string) → epg_channels.channel_id
        //           → epg_channels.id (int PK) → channels.epg_channel_id (int FK)
        //           where channels.user_id = auth()->id()
        $programmesBuilder = EpgProgramme::query()
            ->join('epg_channels', 'epg_channels.channel_id', '=', 'epg_programmes.epg_channel_id')
            ->join('channels', 'channels.epg_channel_id', '=', 'epg_channels.id')
            ->where('channels.user_id', $userId)
            ->where('epg_programmes.start_time', '>=', $now)
            ->where('epg_programmes.start_time', '<=', $weekFromNow)
            ->where(function ($q) use ($query): void {
                $q->where('epg_programmes.title', 'like', "%{$query}%")
                    ->orWhere('epg_programmes.description', 'like', "%{$query}%");
            });

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
            return "No upcoming programmes found matching '{$query}'.";
        }

        $lines = ["Upcoming programmes matching '{$query}' (next 7 days):", ''];

        foreach ($programmes as $programme) {
            $start = $programme->start_time->format('Y-m-d H:i');
            $end = $programme->end_time->format('H:i');
            $channel = $programme->channel_display_name ?? 'Unknown';
            $episodeInfo = '';

            if ($programme->season !== null && $programme->episode !== null) {
                $episodeInfo = sprintf(' S%02dE%02d', $programme->season, $programme->episode);
            }

            $lines[] = "  #{$programme->id} {$programme->title}{$episodeInfo} | {$channel} | {$start} → {$end}";
        }

        $lines[] = '';
        $lines[] = 'Call schedule_once with a programme_id to record a specific programme, or schedule_series with a title to record all future airings.';

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

        // List DVR settings if not provided
        if ($dvrSettingId === null) {
            return $this->listDvrSettings();
        }

        // Validate DVR setting belongs to user
        $dvrSetting = DvrSetting::where('id', $dvrSettingId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $dvrSetting) {
            return "DVR setting #{$dvrSettingId} not found or does not belong to you.";
        }

        // Find programme and validate
        $programme = EpgProgramme::find($programmeId);

        if (! $programme) {
            return "Programme #{$programmeId} not found.";
        }

        if ($programme->start_time <= now()) {
            return "Programme #{$programmeId} has already started or finished. Cannot schedule a once recording for past programmes.";
        }

        // Check for duplicate
        $exists = DvrRecordingRule::where('user_id', auth()->id())
            ->where('dvr_setting_id', $dvrSettingId)
            ->where('type', DvrRuleType::Once)
            ->where('programme_id', $programmeId)
            ->exists();

        if ($exists) {
            return 'A Once rule for this programme already exists.';
        }

        // Resolve channel: epg_programmes.epg_channel_id (string) → EpgChannel.channel_id → EpgChannel.id (PK)
        //                → Channel.epg_channel_id (int FK)
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

        // List DVR settings if not provided
        if ($dvrSettingId === null) {
            return $this->listDvrSettings();
        }

        // Validate DVR setting belongs to user
        $dvrSetting = DvrSetting::where('id', $dvrSettingId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $dvrSetting) {
            return "DVR setting #{$dvrSettingId} not found or does not belong to you.";
        }

        // Check for duplicate
        $exists = DvrRecordingRule::where('user_id', auth()->id())
            ->where('dvr_setting_id', $dvrSettingId)
            ->where('type', DvrRuleType::Series)
            ->where('series_title', $title)
            ->exists();

        if ($exists) {
            return "A Series rule for '{$title}' already exists.";
        }

        // Resolve series mode
        $seriesMode = match ($seriesModeRaw) {
            'new_only' => DvrSeriesMode::NewFlag,
            'unique_se' => DvrSeriesMode::UniqueSe,
            default => DvrSeriesMode::All,
        };

        // Validate channel if provided
        if ($channelId !== null) {
            $channel = Channel::where('id', $channelId)
                ->where('user_id', auth()->id())
                ->where('playlist_id', $dvrSetting->playlist_id)
                ->first();

            if (! $channel) {
                return "Channel #{$channelId} not found, does not belong to you, or is not in the DVR setting's playlist.";
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
}
