<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use App\Enums\PlaylistSourceType;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\Playlist;
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Copilot tool that shows EPG mapping state for a playlist.
 *
 * Without a playlist_id it lists all playlists with overall mapped/unmapped
 * counts. With a playlist_id it returns a per-group breakdown. Either view
 * can be scoped to a single group with the optional group parameter.
 */
class EpgMappingStateTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Show EPG mapping state. FIRST CALL: always omit playlist_id — this lists every playlist with mapped/unmapped counts so the user can choose one. SECOND CALL: pass the playlist_id the user chose to get a per-group breakdown. Never guess or infer a playlist_id; always list playlists first and let the user pick.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'playlist_id' => $schema->integer()
                ->description(__('The playlist ID chosen by the user. Omit on the first call — you must list playlists first so the user can select one.')),
            'group' => $schema->string()
                ->description(__('Filter to a specific group within the playlist. Omit to show all groups.')),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $playlistIdRaw = $request['playlist_id'] ?? null;
        $playlistId = ($playlistIdRaw !== null && (int) $playlistIdRaw > 0) ? (int) $playlistIdRaw : null;
        $group = isset($request['group']) ? trim((string) $request['group']) : null;

        if ($playlistId === null) {
            return $this->listPlaylists();
        }

        return $this->showPlaylistState($playlistId, $group);
    }

    private function listPlaylists(): string
    {
        $mediaServerTypes = [
            PlaylistSourceType::Emby->value,
            PlaylistSourceType::Jellyfin->value,
            PlaylistSourceType::Plex->value,
            PlaylistSourceType::LocalMedia->value,
        ];

        $playlists = Playlist::query()
            ->where('user_id', auth()->id())
            ->select(['id', 'name'])
            ->where(function ($query) use ($mediaServerTypes): void {
                $query->whereNull('source_type')
                    ->orWhereNotIn('source_type', $mediaServerTypes);
            })
            ->orderBy('name')
            ->get();

        if ($playlists->isEmpty()) {
            return 'No playlists found.';
        }

        $lines = ['Available playlists (id | name | mapped/total | unmapped):', ''];

        $playlistIds = $playlists->pluck('id');
        $channelStats = Channel::whereIn('playlist_id', $playlistIds)
            ->where('user_id', auth()->id())
            ->select('playlist_id')
            ->selectRaw('COUNT(*) as total, COUNT(epg_channel_id) as mapped')
            ->groupBy('playlist_id')
            ->get()
            ->keyBy('playlist_id');

        foreach ($playlists as $playlist) {
            $stats = $channelStats->get($playlist->id);
            $total = $stats ? (int) $stats->total : 0;
            $mapped = $stats ? (int) $stats->mapped : 0;
            $unmapped = $total - $mapped;

            $lines[] = "  #{$playlist->id} {$playlist->name} — {$mapped}/{$total} mapped, {$unmapped} unmapped";
        }

        $lines[] = '';
        $lines[] = 'Ask the user which playlist to work on, then call this tool again with that playlist_id to see the per-group breakdown.';

        return implode("\n", $lines);
    }

    private function showPlaylistState(int $playlistId, ?string $group): string
    {
        $playlist = Playlist::where('id', $playlistId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $playlist) {
            return "Playlist #{$playlistId} not found.";
        }

        $query = Channel::where('playlist_id', $playlistId)
            ->where('user_id', auth()->id())
            ->select('group')
            ->selectRaw('COUNT(*) as total, COUNT(epg_channel_id) as mapped')
            ->groupBy('group');

        if ($group !== null) {
            $query->where('group', $group);
        }

        $rows = $query->get()
            ->map(fn ($row) => [
                'group' => (string) ($row->group ?? '(no group)'),
                'total' => (int) $row->total,
                'mapped' => (int) $row->mapped,
                'unmapped' => (int) $row->total - (int) $row->mapped,
            ])
            ->sortByDesc('unmapped')
            ->values();

        if ($rows->isEmpty()) {
            $suffix = $group ? " group \"{$group}\"" : '';

            return "No channels found in playlist #{$playlistId}{$suffix}.";
        }

        $lines = [
            "EPG Mapping State — {$playlist->name} (id: {$playlistId})",
            '',
            str_pad('Group', 42).str_pad('Mapped', 10).str_pad('Unmapped', 10).'Total',
            str_repeat('-', 72),
        ];

        foreach ($rows as $row) {
            $label = mb_substr($row['group'], 0, 40);
            $lines[] = str_pad($label, 42)
                .str_pad((string) $row['mapped'], 10)
                .str_pad((string) $row['unmapped'], 10)
                .$row['total'];
        }

        $totalMapped = $rows->sum('mapped');
        $totalAll = $rows->sum('total');
        $totalUnmapped = $totalAll - $totalMapped;

        $lines[] = str_repeat('-', 72);
        $lines[] = str_pad('TOTAL', 42)
            .str_pad((string) $totalMapped, 10)
            .str_pad((string) $totalUnmapped, 10)
            .$totalAll;

        if ($group === null) {
            $lines[] = '';
            $lines[] = $this->listEpgSources();
            $lines[] = '';
            $lines[] = 'Ask the user which group to work on and which EPG source to use, then call EpgChannelMatcherTool with playlist_id, group, and epg_id.';
        }

        return implode("\n", $lines);
    }

    private function listEpgSources(): string
    {
        $epgs = Epg::query()
            ->where('user_id', auth()->id())
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();

        if ($epgs->isEmpty()) {
            return 'No EPG sources found. The user will need to add one before mapping can begin.';
        }

        $lines = ['Available EPG sources:'];

        foreach ($epgs as $epg) {
            $lines[] = "  #{$epg->id} {$epg->name}";
        }

        return implode("\n", $lines);
    }
}
