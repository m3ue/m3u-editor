<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use App\Models\Network;
use App\Models\NetworkContent;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Copilot tool for pinning network content to a specific day and time.
 *
 * Allows the AI to set or clear weekly time pins on content items
 * in a network playlist (e.g. "play The Wild Robot every Friday at 8pm").
 */
class NetworkContentPinTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Pin or unpin a content item in a network playlist to a specific day of the week and time. Use this to schedule "play X every Friday at 8pm" — the schedule generator will always place the content at that time. To clear a pin, omit pin_day_of_week and pin_time_of_day (or pass null). You must provide the network_content_id, which you can get by listing the network\'s content items.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'network_content_id' => $schema->integer()
                ->description('The ID of the NetworkContent record to pin. List the network\'s content to find this.'),
            'pin_day_of_week' => $schema->string()
                ->description('Day of the week: monday, tuesday, wednesday, thursday, friday, saturday, sunday. Omit or null to clear the pin.'),
            'pin_time_of_day' => $schema->string()
                ->description('Time in 24-hour HH:MM format, e.g. "20:00" for 8pm. Required when setting a pin.'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $ncId = (int) ($request['network_content_id'] ?? 0);
        $day = isset($request['pin_day_of_week']) ? strtolower(trim((string) $request['pin_day_of_week'])) : null;
        $time = isset($request['pin_time_of_day']) ? trim((string) $request['pin_time_of_day']) : null;

        if ($ncId === 0) {
            return 'Error: network_content_id is required.';
        }

        $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        if ($day !== null && ! in_array($day, $validDays, true)) {
            return 'Error: pin_day_of_week must be one of: '.implode(', ', $validDays).'.';
        }

        if ($day !== null && (! $time || ! preg_match('/^\d{2}:\d{2}$/', $time))) {
            return 'Error: pin_time_of_day is required when setting a pin. Use HH:MM format, e.g. "20:00".';
        }

        $nc = NetworkContent::find($ncId);

        if (! $nc) {
            return "Error: Network content item {$ncId} not found.";
        }

        // Verify the network belongs to the authenticated user
        $network = Network::where('id', $nc->network_id)
            ->where('user_id', auth()->id())
            ->first();

        if (! $network) {
            return 'Error: Network not found or you do not have access to it.';
        }

        $clearing = $day === null || $time === null;

        $nc->update([
            'pin_day_of_week' => $clearing ? null : $day,
            'pin_time_of_day' => $clearing ? null : $time,
        ]);

        $contentTitle = $nc->title;

        if ($clearing) {
            return "Cleared pin for \"{$contentTitle}\" in network \"{$network->name}\". It will now play in normal rotation.";
        }

        // The NetworkContent::updated listener only auto-regenerates when the
        // network has auto_regenerate_schedule enabled, so don't overpromise.
        $regenNote = $network->auto_regenerate_schedule
            ? ' The schedule will be regenerated automatically.'
            : ' The schedule will be regenerated on the next manual generation.';

        return "Pinned \"{$contentTitle}\" to ".ucfirst($day)."s at {$time} in network \"{$network->name}\".{$regenNote}";
    }
}
