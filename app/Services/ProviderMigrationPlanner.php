<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\EpgChannel;
use App\Models\Playlist;
use Illuminate\Support\Collection;

/**
 * Builds a read-only migration plan: which source (expired provider) channels map to which
 * target (working provider) channels, what would change on each target channel, and what would
 * happen to the EPG mapping. Performs no writes.
 *
 * Shared by the interactive resolution page (to render) and by CopyAttributesToPlaylist in
 * migration mode (to recompute + staleness-check before applying).
 */
class ProviderMigrationPlanner
{
    /** Fields that migration mode is allowed to copy onto a matched target channel. */
    public const COPYABLE_FIELDS = ['enabled', 'group', 'sort', 'channel', 'shift', 'name', 'title', 'logo', 'station_id'];

    public function __construct(
        private readonly ChannelMatchResolver $resolver,
    ) {}

    /**
     * @param  array{passes?: list<string>, preserve_epg?: bool}  $options
     * @return array{
     *     source_playlist_id: int,
     *     target_playlist_id: int,
     *     fingerprint: string,
     *     passes: list<string>,
     *     matched: list<array<string, mixed>>,
     *     ambiguous: list<array<string, mixed>>,
     *     unmatched_source: list<array<string, mixed>>,
     *     target_only: list<array<string, mixed>>
     * }
     */
    public function plan(Playlist $source, int $targetId, array $options = []): array
    {
        $target = Playlist::findOrFail($targetId);
        $passes = $options['passes'] ?? ChannelMatchResolver::DEFAULT_PASSES;
        $preserveEpg = (bool) ($options['preserve_epg'] ?? false);

        $sourceRows = $this->loadLiveChannels($source);
        $targetRows = $this->loadLiveChannels($target);

        $result = $this->resolver->resolve($sourceRows, $targetRows, $passes);

        $sourceById = $sourceRows->keyBy('id');
        $targetById = $targetRows->keyBy('id');

        $epgTargetIndex = $preserveEpg ? $this->buildTargetEpgIndex($target) : [];

        $matched = [];
        foreach ($result['matched'] as $sourceId => $match) {
            $sourceChannel = $sourceById->get($sourceId);
            $targetChannel = $targetById->get($match['target_id']);
            if (! $sourceChannel || ! $targetChannel) {
                continue;
            }

            $matched[] = [
                'source' => $this->channelSummary($sourceChannel),
                'target' => $this->channelSummary($targetChannel),
                'match_pass' => $match['pass'],
                'match_key' => $match['key'],
                'epg' => $preserveEpg
                    ? $this->epgProposal($source, $sourceChannel, $targetChannel, $epgTargetIndex)
                    : null,
            ];
        }

        $ambiguous = [];
        foreach ($result['ambiguous'] as $sourceId => $info) {
            $sourceChannel = $sourceById->get($sourceId);
            if (! $sourceChannel) {
                continue;
            }
            $ambiguous[] = [
                'source' => $this->channelSummary($sourceChannel),
                'match_pass' => $info['pass'],
                'match_key' => $info['key'],
                'candidates' => collect($info['target_ids'])
                    ->map(fn ($id) => $targetById->get($id))
                    ->filter()
                    ->map(fn ($c) => $this->channelSummary($c))
                    ->values()
                    ->all(),
            ];
        }

        $unmatchedSource = collect($result['unmatched_source'])
            ->map(fn ($id) => $sourceById->get($id))
            ->filter()
            ->map(fn ($c) => ['source' => $this->channelSummary($c)])
            ->values()
            ->all();

        $targetOnly = collect($result['unmatched_target'])
            ->map(fn ($id) => $targetById->get($id))
            ->filter()
            ->map(fn ($c) => ['target' => $this->channelSummary($c)])
            ->values()
            ->all();

        return [
            'source_playlist_id' => $source->id,
            'target_playlist_id' => $target->id,
            'fingerprint' => $this->fingerprint($source, $target),
            'passes' => array_values($passes),
            'matched' => $matched,
            'ambiguous' => $ambiguous,
            'unmatched_source' => $unmatchedSource,
            'target_only' => $targetOnly,
        ];
    }

    /**
     * Deterministic fingerprint of the live-channel sets on both playlists. If either set or
     * its newest updated_at changes between preview and apply, the plan is stale.
     */
    public function fingerprint(Playlist $source, Playlist $target): string
    {
        $summarize = static function (Playlist $playlist): string {
            $row = $playlist->channels()
                ->where('is_vod', false)
                ->selectRaw('count(*) as cnt, max(updated_at) as newest, min(id) as lo, max(id) as hi')
                ->first();

            return implode(':', [
                (int) ($row->cnt ?? 0),
                (string) ($row->newest ?? ''),
                (int) ($row->lo ?? 0),
                (int) ($row->hi ?? 0),
            ]);
        };

        return hash('sha256', $source->id.'|'.$summarize($source).'||'.$target->id.'|'.$summarize($target));
    }

    /**
     * @return Collection<int, Channel>
     */
    private function loadLiveChannels(Playlist $playlist): Collection
    {
        return $playlist->channels()
            ->where('is_vod', false)
            ->select([
                'id', 'playlist_id', 'name', 'name_custom', 'title', 'title_custom',
                'stream_id', 'stream_id_custom', 'enabled', 'group', 'group_id',
                'channel', 'sort', 'shift', 'station_id', 'logo', 'logo_internal',
                'epg_channel_id', 'epg_map_enabled',
            ])
            ->get();
    }

    /**
     * Build a lookup of the target playlist's own EPG channels by normalized identity so an
     * expired-provider mapping can be re-pointed at the working provider's equivalent.
     *
     * @return array<string, int>
     */
    private function buildTargetEpgIndex(Playlist $target): array
    {
        $epgIds = $target->epgMaps()->pluck('epg_id')
            ->merge($target->epgs()->pluck('id'))
            ->filter()
            ->unique()
            ->values();

        if ($epgIds->isEmpty()) {
            return [];
        }

        $index = [];
        EpgChannel::query()
            ->whereIn('epg_id', $epgIds)
            ->select(['id', 'channel_id', 'name', 'display_name', 'additional_display_names'])
            ->cursor()
            ->each(function (EpgChannel $epgChannel) use (&$index) {
                foreach ($this->epgIdentityKeys($epgChannel) as $key) {
                    // First writer wins; keeps the mapping deterministic.
                    $index[$key] ??= (int) $epgChannel->id;
                }
            });

        return $index;
    }

    /**
     * @return list<string>
     */
    private function epgIdentityKeys(EpgChannel $epgChannel): array
    {
        $keys = [];
        foreach ([$epgChannel->channel_id, $epgChannel->name, $epgChannel->display_name] as $value) {
            $normalized = $this->resolver->normalize($value);
            if ($normalized !== null) {
                $keys[] = $normalized;
            }
        }
        foreach ((array) ($epgChannel->additional_display_names ?? []) as $value) {
            $normalized = $this->resolver->normalize($value);
            if ($normalized !== null) {
                $keys[] = $normalized;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, int>  $epgTargetIndex
     * @return array<string, mixed>
     */
    private function epgProposal(Playlist $source, Channel $sourceChannel, Channel $targetChannel, array $epgTargetIndex): array
    {
        if (empty($sourceChannel->epg_channel_id)) {
            return ['status' => 'none'];
        }

        $sourceEpg = EpgChannel::query()
            ->select(['id', 'channel_id', 'name', 'display_name', 'additional_display_names', 'user_id'])
            ->find($sourceChannel->epg_channel_id);

        if (! $sourceEpg || (int) $sourceEpg->user_id !== (int) $source->user_id) {
            return ['status' => 'unavailable'];
        }

        // Prefer re-mapping to the target provider's own EPG channel with the same identity.
        $proposedId = null;
        $strategy = null;
        foreach ($this->epgIdentityKeys($sourceEpg) as $key) {
            if (isset($epgTargetIndex[$key])) {
                $proposedId = $epgTargetIndex[$key];
                $strategy = 'identity_remap';
                break;
            }
        }

        if ($proposedId === null) {
            // Fall back to copying the FK verbatim; programme data may be gone with the provider.
            $proposedId = (int) $sourceChannel->epg_channel_id;
            $strategy = 'fk_copy';
        }

        $currentTargetEpgId = $targetChannel->epg_channel_id ? (int) $targetChannel->epg_channel_id : null;
        $conflict = $currentTargetEpgId !== null && $currentTargetEpgId !== $proposedId;

        return [
            'status' => $conflict ? 'conflict' : 'ok',
            'strategy' => $strategy,
            'proposed_epg_channel_id' => $proposedId,
            'current_target_epg_channel_id' => $currentTargetEpgId,
            'programme_data_warning' => $strategy === 'fk_copy',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function channelSummary(Channel $channel): array
    {
        return [
            'id' => (int) $channel->id,
            'name' => $channel->name_custom ?: $channel->name,
            'title' => $channel->title_custom ?: $channel->title,
            'stream_id' => $channel->stream_id_custom ?: $channel->stream_id,
            'group' => $channel->group,
            'enabled' => (bool) $channel->enabled,
            'channel_number' => $channel->channel,
            'sort' => $channel->sort,
            'epg_channel_id' => $channel->epg_channel_id ? (int) $channel->epg_channel_id : null,
        ];
    }
}
