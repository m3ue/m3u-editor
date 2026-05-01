<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use App\Models\Channel;
use App\Models\EpgChannel;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Copilot tool that writes confirmed EPG channel mappings to the database.
 *
 * Accepts a JSON array of {channel_id, epg_channel_id} pairs, validates that
 * both IDs exist, then applies them in a single transaction. Only call this
 * after presenting the full plan to the user and receiving explicit approval.
 */
class EpgMappingApplyTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Apply confirmed EPG channel mappings to the database. Pass a JSON array of {"channel_id": int, "epg_channel_id": int} pairs. Only call this after presenting the mapping plan to the user and receiving explicit approval. Each mapping sets Channel.epg_channel_id to the matched EpgChannel.id.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'mappings' => $schema->string()
                ->description('JSON array of confirmed mappings. Format: [{"channel_id": 123, "epg_channel_id": 456}, {"channel_id": 124, "epg_channel_id": 789}]')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $raw = trim((string) ($request['mappings'] ?? ''));

        if ($raw === '') {
            return 'No mappings provided.';
        }

        $mappings = json_decode($raw, true);

        if (! is_array($mappings) || empty($mappings)) {
            return 'Invalid mappings format. Expected a JSON array of {"channel_id": int, "epg_channel_id": int} objects.';
        }

        // Validate structure of each entry before touching the database.
        $channelIds = [];
        $epgChannelIds = [];

        foreach ($mappings as $i => $mapping) {
            if (! is_array($mapping) || ! isset($mapping['channel_id'], $mapping['epg_channel_id'])) {
                return "Entry at index {$i} is missing channel_id or epg_channel_id.";
            }

            $channelIds[] = (int) $mapping['channel_id'];
            $epgChannelIds[] = (int) $mapping['epg_channel_id'];
        }

        // Fetch valid IDs in two queries rather than validating one-by-one.
        // Channel IDs are scoped to the current user to prevent cross-user manipulation.
        $validChannelIds = Channel::where('user_id', auth()->id())
            ->whereIn('id', $channelIds)
            ->pluck('id')
            ->flip()
            ->all();

        $validEpgChannelIds = EpgChannel::whereIn('id', $epgChannelIds)
            ->pluck('id')
            ->flip()
            ->all();

        $toApply = [];
        $skipped = [];

        foreach ($mappings as $mapping) {
            $channelId = (int) $mapping['channel_id'];
            $epgChannelId = (int) $mapping['epg_channel_id'];

            if (! isset($validChannelIds[$channelId])) {
                $skipped[] = "Channel #{$channelId} not found — skipped.";

                continue;
            }

            if (! isset($validEpgChannelIds[$epgChannelId])) {
                $skipped[] = "EpgChannel #{$epgChannelId} not found — skipped (channel #{$channelId} left unmapped).";

                continue;
            }

            $toApply[] = ['channel_id' => $channelId, 'epg_channel_id' => $epgChannelId];
        }

        if (empty($toApply)) {
            $lines = ['No valid mappings to apply.'];

            if (! empty($skipped)) {
                array_push($lines, '', ...$skipped);
            }

            return implode("\n", $lines);
        }

        $groupedToApply = [];
        foreach ($toApply as $mapping) {
            $groupedToApply[$mapping['epg_channel_id']][] = $mapping['channel_id'];
        }

        $applied = 0;

        DB::transaction(function () use ($groupedToApply, &$applied): void {
            foreach ($groupedToApply as $epgChannelId => $channelIds) {
                Channel::whereIn('id', $channelIds)
                    ->where('user_id', auth()->id())
                    ->update(['epg_channel_id' => $epgChannelId]);

                $applied += count($channelIds);
            }
        });

        $lines = ["Applied {$applied} mapping(s) successfully."];

        if (! empty($skipped)) {
            $lines[] = '';
            $lines[] = 'Skipped:';
            array_push($lines, ...$skipped);
        }

        $lines[] = '';
        $lines[] = 'Call EpgMappingStateTool to see the updated mapping state, or EpgChannelMatcherTool to continue with the next batch.';

        return implode("\n", $lines);
    }
}
