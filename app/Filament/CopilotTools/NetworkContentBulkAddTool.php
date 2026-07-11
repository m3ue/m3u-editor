<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use App\Models\Channel;
use App\Models\Network;
use App\Models\NetworkContent;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Copilot tool that bulk-adds VOD channels to a media network playlist.
 *
 * Validates network ownership, skips duplicates using NetworkContent::findForNetwork(),
 * and appends items after the current highest sort_order. The NetworkContent model's
 * created hook dispatches a debounced, unique schedule regeneration job so bulk
 * inserts collapse into a single regen rather than one per row.
 */
class NetworkContentBulkAddTool extends BaseTool
{
    private const MAX_CHANNELS = 200;

    public function description(): Stringable|string
    {
        return 'Bulk-add VOD channels to a media network playlist. Use this after the user has confirmed a list of channel IDs from VodContentSearchTool. Duplicate entries are detected and skipped automatically. Returns a summary of added vs skipped items. Always confirm with the user which network to add content to, and which titles they want — do not call this speculatively. Use ListRecords or SearchRecords on the Network resource if you need to find the network_id.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'network_id' => $schema->integer()
                ->description('The ID of the media network (playlist) to add content to.')
                ->required(),
            'channel_ids' => $schema->array()
                ->items($schema->integer())
                ->description('Array of channel IDs to add. Obtain these from VodContentSearchTool results. Maximum 200 per call.')
                ->required(),
            'weight' => $schema->integer()
                ->description('Scheduling weight for each item (default: 1). Higher values make the item play more often in weighted schedules.'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $networkId = (int) $request['network_id'];
        $channelIds = array_values(array_filter(array_map('intval', (array) ($request['channel_ids'] ?? []))));
        $weight = max(1, (int) ($request['weight'] ?? 1));

        if (empty($channelIds)) {
            return 'No channel IDs provided.';
        }

        if (count($channelIds) > self::MAX_CHANNELS) {
            return 'Too many channel IDs — maximum is '.self::MAX_CHANNELS.' per call. Split your list into batches.';
        }

        $network = Network::where('id', $networkId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $network) {
            return "Network #{$networkId} not found or does not belong to you.";
        }

        $maxSortOrder = $network->networkContent()->max('sort_order') ?? 0;
        $sortIndex = 0;
        $added = [];
        $skipped = [];
        $notFound = [];

        foreach ($channelIds as $channelId) {
            $channel = Channel::where('id', $channelId)
                ->where('user_id', auth()->id())
                ->first();

            if (! $channel) {
                $notFound[] = $channelId;

                continue;
            }

            if (NetworkContent::findForNetwork($network, $channel) !== null) {
                $skipped[] = $channel->name;

                continue;
            }

            $network->networkContent()->create([
                'contentable_type' => Channel::class,
                'contentable_id' => $channel->id,
                'sort_order' => $maxSortOrder + $sortIndex + 1,
                'weight' => $weight,
            ]);

            $added[] = $channel->name;
            $sortIndex++;
        }

        $lines = [
            "Network \"{$network->name}\" updated.",
            '',
            'Added: '.count($added).' item(s)',
        ];

        foreach ($added as $name) {
            $lines[] = "  + {$name}";
        }

        if (! empty($skipped)) {
            $lines[] = '';
            $lines[] = 'Skipped (already in network): '.count($skipped);

            foreach ($skipped as $name) {
                $lines[] = "  = {$name}";
            }
        }

        if (! empty($notFound)) {
            $lines[] = '';
            $lines[] = 'Not found (ID not in your library): '.implode(', ', $notFound);
        }

        if ($sortIndex > 0 && in_array($network->schedule_type, ['sequential', 'shuffle'], true)) {
            $lines[] = '';
            $lines[] = 'A schedule regeneration has been queued automatically.';
        }

        return implode("\n", $lines);
    }
}
