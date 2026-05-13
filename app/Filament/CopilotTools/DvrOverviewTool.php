<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Copilot tool that provides operational awareness of the DVR subsystem.
 *
 * Use this when the user asks about:
 * - What's currently recording or scheduled
 * - Recent failed or completed recordings
 * - DVR recording rules and their status
 * - Disk usage or concurrent recording capacity
 */
class DvrOverviewTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Get an overview of the DVR system. Views: "status" (currently recording + upcoming + recent failures), "rules" (active recording rules), "capacity" (concurrent slots + disk usage per DVR setting), "recent" (latest recordings). Defaults to "status".';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'view' => $schema->string()
                ->description(__('The overview view to return: status, rules, capacity, recent. Default: status')),
            'limit' => $schema->integer()
                ->description(__('Maximum number of records to return (default: 10, max: 50)')),
            'status_filter' => $schema->string()
                ->description(__('For "recent" view, filter by status: scheduled, recording, post_processing, completed, failed, cancelled')),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $view = strtolower(trim((string) ($request['view'] ?? 'status')));
        $limit = min(50, max(1, (int) ($request['limit'] ?? 10)));
        $statusFilter = isset($request['status_filter'])
            ? strtolower(trim((string) $request['status_filter']))
            : null;

        return match ($view) {
            'rules' => $this->rulesOverview($limit),
            'capacity' => $this->capacityOverview(),
            'recent' => $this->recentOverview($limit, $statusFilter),
            default => $this->statusOverview($limit),
        };
    }

    /** Status view: currently recording, upcoming scheduled, recent failures. */
    private function statusOverview(int $limit): string
    {
        $userId = auth()->id();

        $currentlyRecording = DvrRecording::where('user_id', $userId)
            ->where('status', DvrRecordingStatus::Recording)
            ->with(['channel', 'dvrSetting.playlist'])
            ->orderBy('scheduled_start')
            ->get();

        $upcoming = DvrRecording::where('user_id', $userId)
            ->where('status', DvrRecordingStatus::Scheduled)
            ->with(['channel', 'dvrSetting.playlist'])
            ->orderBy('scheduled_start')
            ->limit($limit)
            ->get();

        $recentFailures = DvrRecording::where('user_id', $userId)
            ->where('status', DvrRecordingStatus::Failed)
            ->with(['channel', 'dvrSetting.playlist'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $lines = ['DVR Status Overview', ''];

        // Currently Recording
        $lines[] = '=== Currently Recording ===';
        if ($currentlyRecording->isEmpty()) {
            $lines[] = 'Nothing is currently recording.';
        } else {
            foreach ($currentlyRecording as $r) {
                $lines[] = $this->formatRecording($r);
            }
        }
        $lines[] = '';

        // Upcoming
        $lines[] = '=== Upcoming Schedule ===';
        if ($upcoming->isEmpty()) {
            $lines[] = 'No upcoming recordings.';
        } else {
            foreach ($upcoming as $r) {
                $lines[] = $this->formatRecording($r);
            }
        }
        $lines[] = '';

        // Recent Failures
        $lines[] = '=== Recent Failures ===';
        if ($recentFailures->isEmpty()) {
            $lines[] = 'No recent failures.';
        } else {
            foreach ($recentFailures as $r) {
                $lines[] = $this->formatRecording($r, true);
            }
        }

        return implode("\n", $lines);
    }

    /** Rules view: active recording rules. */
    private function rulesOverview(int $limit): string
    {
        $userId = auth()->id();

        $rules = DvrRecordingRule::where('user_id', $userId)
            ->where('enabled', true)
            ->with(['channel', 'dvrSetting.playlist', 'recordings'])
            ->orderByDesc('priority')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $lines = ['DVR Recording Rules', ''];

        if ($rules->isEmpty()) {
            $lines[] = 'No active recording rules found.';

            return implode("\n", $lines);
        }

        foreach ($rules as $rule) {
            $typeLabel = $rule->type->getLabel();
            $recordingCount = $rule->recordings->count();
            $channelName = $rule->channel?->name ?? 'Any channel';
            $playlistName = $rule->dvrSetting?->playlist?->name ?? "DVR #{$rule->dvr_setting_id}";

            $line = "#{$rule->id} [{$typeLabel}] ";

            if ($rule->type === DvrRuleType::Series) {
                $line .= $rule->series_title ?? '(no title)';
                $line .= " — mode: {$rule->series_mode->getLabel()}";
            } elseif ($rule->type === DvrRuleType::Once && $rule->programme_id) {
                $line .= 'Once — programme #'.$rule->programme_id;
            } else {
                $line .= 'Manual';
            }

            $line .= " | {$channelName} | {$playlistName} | priority: {$rule->priority} | recordings: {$recordingCount}";

            if ($rule->new_only) {
                $line .= ' | new-only';
            }

            $lines[] = $line;
        }

        $totalRules = DvrRecordingRule::where('user_id', $userId)
            ->where('enabled', true)
            ->count();

        $lines[] = '';
        $lines[] = "Total active rules: {$totalRules}";

        return implode("\n", $lines);
    }

    /** Capacity view: concurrent slots and disk usage per DVR setting. */
    private function capacityOverview(): string
    {
        $userId = auth()->id();

        $settings = DvrSetting::where('user_id', $userId)
            ->with('playlist')
            ->get();

        $lines = ['DVR Capacity Overview', ''];

        if ($settings->isEmpty()) {
            $lines[] = 'No DVR settings found.';

            return implode("\n", $lines);
        }

        foreach ($settings as $setting) {
            $name = $setting->playlist?->name ?? "DVR Setting #{$setting->id}";
            $lines[] = "--- {$name} ---";

            // Concurrent recordings
            $activeCount = DvrRecording::where('user_id', $userId)
                ->where('dvr_setting_id', $setting->id)
                ->whereIn('status', [
                    DvrRecordingStatus::Recording,
                    DvrRecordingStatus::PostProcessing,
                ])
                ->count();

            $lines[] = "Concurrent: {$activeCount} / {$setting->max_concurrent_recordings} active";

            // Disk usage
            $totalBytes = DvrRecording::where('user_id', $userId)
                ->where('dvr_setting_id', $setting->id)
                ->whereNotNull('file_size_bytes')
                ->sum('file_size_bytes');

            $totalGb = round($totalBytes / 1024 / 1024 / 1024, 2);
            $quotaGb = $setting->global_disk_quota_gb;

            if ($quotaGb) {
                $pct = round(($totalGb / $quotaGb) * 100, 1);
                $lines[] = "Disk: {$totalGb} GB / {$quotaGb} GB ({$pct}%)";
            } else {
                $lines[] = "Disk: {$totalGb} GB used (no quota set)";
            }

            // Recording counts by status
            $statusCounts = DvrRecording::where('user_id', $userId)
                ->where('dvr_setting_id', $setting->id)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->all();

            $statusLine = collect($statusCounts)
                ->map(fn (int $count, string $status): string => "{$status}: {$count}")
                ->implode(', ');

            if ($statusLine !== '') {
                $lines[] = "Recordings: {$statusLine}";
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /** Recent view: latest recordings with optional status filter. */
    private function recentOverview(int $limit, ?string $statusFilter): string
    {
        $userId = auth()->id();

        $query = DvrRecording::where('user_id', $userId)
            ->with(['channel', 'dvrSetting.playlist'])
            ->orderByDesc('updated_at')
            ->limit($limit);

        if ($statusFilter) {
            try {
                $statusEnum = DvrRecordingStatus::from($statusFilter);
                $query->where('status', $statusEnum);
            } catch (\ValueError) {
                return "Invalid status_filter: '{$statusFilter}'. Use: scheduled, recording, post_processing, completed, failed, cancelled.";
            }
        }

        $recordings = $query->get();

        $lines = ['Recent Recordings', ''];

        if ($recordings->isEmpty()) {
            $filterLabel = $statusFilter ? " (status: {$statusFilter})" : '';
            $lines[] = "No recordings found{$filterLabel}.";

            return implode("\n", $lines);
        }

        foreach ($recordings as $r) {
            $lines[] = $this->formatRecording($r);
        }

        return implode("\n", $lines);
    }

    private function formatRecording(DvrRecording $recording, bool $includeError = false): string
    {
        $status = $recording->status->value;
        $title = $recording->display_title;
        $channel = $recording->channel?->name ?? 'Unknown channel';
        $start = $recording->scheduled_start?->format('Y-m-d H:i') ?? 'N/A';
        $end = $recording->scheduled_end?->format('Y-m-d H:i') ?? 'N/A';

        $line = "#{$recording->id} [{$status}] {$title} | {$channel} | {$start} → {$end}";

        if ($recording->season !== null && $recording->episode !== null) {
            $line .= sprintf(' | S%02dE%02d', $recording->season, $recording->episode);
        }

        if ($recording->file_size_bytes) {
            $sizeMb = round($recording->file_size_bytes / 1024 / 1024, 1);
            $line .= " | {$sizeMb} MB";
        }

        if ($includeError && $recording->error_message) {
            $error = mb_substr($recording->error_message, 0, 120);
            $line .= " | Error: {$error}";
        }

        return $line;
    }
}
