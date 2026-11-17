<?php

namespace App\Services;

use App\Http\Controllers\MediaServerProxyController;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Episode;
use App\Models\EpisodeFailover;
use App\Models\Scopes\ExcludeAioFailoverClonesScope;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Shared candidate handling for AIOStreams-backed VOD Channels and Episodes:
 * fetching + ranking streams, writing the selected candidates as the item's
 * primary URL plus an ordered failover chain, and the manual "pick and order
 * the streams yourself" flow.
 *
 * A manual selection is remembered as an ordered list of stream keys (see
 * streamKey()) under the item's `aiostreams.manual_keys`. Every automatic
 * re-resolution (bulk/series rescan, the playback-exhaustion hook) re-matches
 * those keys against the fresh results first, so a link refresh keeps the
 * user's picks instead of letting the auto-ranker swap them back out. Only
 * when none of the picked streams are offered anymore does it fall back to
 * the automatic ranking (and forgets the stale manual selection).
 */
class AioStreamsCandidateService
{
    /**
     * How long fetched streams are held server-side between opening the
     * manual picker and saving it. The raw stream URLs never go into
     * Livewire state (they're often debrid-account-scoped), so the picker
     * only carries opaque keys and reads the streams back from here.
     */
    public const MANUAL_CACHE_MINUTES = 30;

    public function __construct(private readonly AioStreamsQualityParser $parser) {}

    /**
     * @return array<int, array{parsed: array<string, mixed>, stream: array<string, mixed>}>
     */
    public function fetchRanked(Channel|Episode $item): array
    {
        $result = $item instanceof Channel
            ? AIOStreamsService::make($item->aioIntegration)->fetchStreams($item->aio_type ?? 'movie', $item->aio_item_id)
            : AIOStreamsService::make($item->series->aioIntegration)->fetchStreams('series', $item->aio_item_id);

        return $this->parser->rank($result['streams'] ?? []);
    }

    /**
     * Pick the candidates to store for an automatic resolution: the user's
     * manual selection (re-matched, in their order) when any of it is still
     * offered, otherwise the top-N of the automatic ranking. Returns the
     * selected candidates and the manual keys to keep (null = automatic).
     *
     * @param  array<int, array{parsed: array<string, mixed>, stream: array<string, mixed>}>  $ranked
     * @return array{0: array<int, array{parsed: array<string, mixed>, stream: array<string, mixed>}>, 1: ?array<int, string>}
     */
    public function selectForAutomaticResolution(Channel|Episode $item, array $ranked): array
    {
        $manualKeys = $this->manualKeys($item);

        if ($manualKeys !== []) {
            $matched = $this->selectByKeys($ranked, $manualKeys);

            if ($matched !== []) {
                return [$matched, $manualKeys];
            }
        }

        return [array_slice($ranked, 0, max(1, $this->maxCandidates())), null];
    }

    /**
     * @param  array<int, array{parsed: array<string, mixed>, stream: array<string, mixed>}>  $ranked
     * @param  array<int, string>  $keys
     * @return array<int, array{parsed: array<string, mixed>, stream: array<string, mixed>}>
     */
    public function selectByKeys(array $ranked, array $keys): array
    {
        $byKey = [];
        foreach ($ranked as $candidate) {
            $byKey[self::streamKey($candidate['stream'])] ??= $candidate;
        }

        return collect($keys)
            ->unique()
            ->map(fn (string $key) => $byKey[$key] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Stable identity for a stream across fetches. The release filename
     * (plus size) is the most stable thing addons expose; falls back to the
     * torrent hash/file index, then the URL, then the display text.
     *
     * @param  array<string, mixed>  $stream
     */
    public static function streamKey(array $stream): string
    {
        $hints = $stream['behaviorHints'] ?? [];

        $identity = match (true) {
            ! empty($hints['filename']) => 'file:'.$hints['filename'].'|'.($hints['videoSize'] ?? ''),
            ! empty($stream['infoHash']) => 'hash:'.$stream['infoHash'].'|'.($stream['fileIdx'] ?? ''),
            ! empty($stream['url']) => 'url:'.$stream['url'],
            default => 'text:'.($stream['name'] ?? '').'|'.($stream['title'] ?? $stream['description'] ?? ''),
        };

        return sha1($identity);
    }

    /**
     * @return array<int, string>
     */
    public function manualKeys(Channel|Episode $item): array
    {
        $data = $item instanceof Channel ? ($item->movie_data ?? []) : ($item->info ?? []);

        return array_values(array_filter((array) ($data['aiostreams']['manual_keys'] ?? []), 'is_string'));
    }

    public function isManual(Channel|Episode $item): bool
    {
        return $this->manualKeys($item) !== [];
    }

    /**
     * Forget a manual selection so the next resolution is fully automatic.
     */
    public function clearManualSelection(Channel|Episode $item): void
    {
        $column = $item instanceof Channel ? 'movie_data' : 'info';
        $data = $item->{$column} ?? [];

        if (! isset($data['aiostreams']['manual_keys'])) {
            return;
        }

        unset($data['aiostreams']['manual_keys']);
        $item->update([$column => $data]);
    }

    /**
     * Fetch the current streams for the manual picker, park them in the cache
     * and return the Repeater rows (display-only fields + opaque key). The
     * current selection (manual or automatic) is pre-selected and listed
     * first in its stored order, so the user only has to tweak it.
     *
     * @return array<string, array{key: string, selected: bool, name: string, details: string}>
     */
    public function loadManualCandidates(Channel|Episode $item): array
    {
        $ranked = $this->fetchRanked($item);

        Cache::put($this->manualCacheKey($item), $ranked, now()->addMinutes(self::MANUAL_CACHE_MINUTES));

        $currentKeys = $this->manualKeys($item);
        if ($currentKeys === []) {
            [$selected] = $this->selectForAutomaticResolution($item, $ranked);
            $currentKeys = $this->streamKeys($selected);
        }

        $selectedFirst = collect($this->selectByKeys($ranked, $currentKeys));
        $rest = collect($ranked)->reject(fn (array $c) => in_array(self::streamKey($c['stream']), $currentKeys, true));

        return $selectedFirst->concat($rest)
            ->mapWithKeys(function (array $candidate) use ($currentKeys) {
                $stream = $candidate['stream'];
                $key = self::streamKey($stream);

                return [(string) Str::uuid() => [
                    'key' => $key,
                    'selected' => in_array($key, $currentKeys, true),
                    'name' => trim(preg_replace('/\s+/', ' ', (string) ($stream['name'] ?? __('Unknown source')))),
                    'details' => (string) ($stream['description'] ?? $stream['title'] ?? ''),
                ]];
            })
            ->all();
    }

    /**
     * Apply the user's picked + ordered keys from the manual picker.
     *
     * @param  array<int, string>  $orderedKeys
     * @return int The number of candidates stored (0 = the cached streams expired or nothing matched).
     */
    public function applyManualSelection(Channel|Episode $item, array $orderedKeys): int
    {
        $ranked = Cache::get($this->manualCacheKey($item));
        if (! is_array($ranked)) {
            return 0;
        }

        $selected = $this->selectByKeys($ranked, $orderedKeys);
        if ($selected === []) {
            return 0;
        }

        $this->apply($item, $selected, $this->streamKeys($selected));
        Cache::forget($this->manualCacheKey($item));

        return count($selected);
    }

    /**
     * Render the stream's addon description (multi-line, emoji-annotated in
     * AIOStreams' default formatter) for display in the picker.
     */
    public static function detailsHtml(?string $details): HtmlString
    {
        return new HtmlString(nl2br(e((string) $details)));
    }

    /**
     * Store the selected candidates: the first as the item's own (proxied)
     * stream, the rest as hidden failover clones in order.
     *
     * @param  array<int, array{parsed: array<string, mixed>, stream: array<string, mixed>}>  $selected
     * @param  ?array<int, string>  $manualKeys  Keys to remember as a manual selection, or null for automatic.
     */
    public function apply(Channel|Episode $item, array $selected, ?array $manualKeys = null): void
    {
        // Re-read first: a resolve job loads its row before a multi-second stream
        // fetch, and the TMDB enrichment queued on add-to-library may have written
        // the same JSON column (episode info) in the meantime - writing back the
        // stale copy would silently drop that metadata.
        $item->refresh();

        if ($item instanceof Channel) {
            $this->applyToChannel($item, $selected, $manualKeys);
        } else {
            $this->applyToEpisode($item, $selected, $manualKeys);
        }
    }

    /**
     * @param  array<int, array{parsed: array<string, mixed>, stream: array<string, mixed>}>  $selected
     * @param  ?array<int, string>  $manualKeys
     */
    protected function applyToChannel(Channel $channel, array $selected, ?array $manualKeys): void
    {
        $this->clearChannelFailovers($channel);

        $primary = $selected[0];
        $movieData = $channel->movie_data ?? [];
        $movieData['aiostreams'] = $this->aiostreamsData($selected, $manualKeys);

        // $channel already exists, so unlike the failover clones below it
        // doesn't need a create-then-update round trip - its own ID is
        // already known, so the proxy URL can go in this same write.
        $channel->update([
            'url' => MediaServerProxyController::generateAioStreamsChannelProxyUrl($channel->aio_integration_id, $channel->id),
            'container_extension' => $primary['parsed']['container'] ?? $channel->container_extension,
            'movie_data' => $movieData,
            'aio_resolution_status' => $this->resolutionStatus($selected, $manualKeys),
            'aio_last_resolved_at' => now(),
        ]);

        foreach (array_slice($selected, 1) as $index => $candidate) {
            // is_aio_failover_clone=true: a lightweight sibling Channel row that only
            // exists so ChannelFailover has a real row to point at. It must never show
            // up anywhere channels are listed for actual browsing/playback (Xtream API,
            // M3U export, VOD/relation-manager tables) - Channel's global scope
            // (ExcludeAioFailoverClonesScope) hides it everywhere except
            // failoverChannels(), which opts back in explicitly.
            $failoverChannel = Channel::create([
                'user_id' => $channel->user_id,
                'playlist_id' => $channel->playlist_id,
                'name' => $channel->name,
                'title' => $channel->title,
                'group_internal' => trim(($channel->group_internal ?? '').'-aio-'.($index + 1), '-'),
                'is_custom' => true,
                'is_vod' => $channel->is_vod,
                'is_aio_failover_clone' => true,
                'movie_data' => ['aiostreams' => ['resolved_url' => $candidate['stream']['url'] ?? null]],
                'container_extension' => $candidate['parsed']['container'] ?? null,
                'aio_integration_id' => $channel->aio_integration_id,
                'aio_item_id' => $channel->aio_item_id,
                'aio_type' => $channel->aio_type,
                'aio_resolution_status' => 'resolved',
                'aio_last_resolved_at' => now(),
            ]);

            $failoverChannel->update([
                'url' => MediaServerProxyController::generateAioStreamsChannelProxyUrl($channel->aio_integration_id, $failoverChannel->id),
            ]);

            ChannelFailover::create([
                'user_id' => $channel->user_id,
                'channel_id' => $channel->id,
                'channel_failover_id' => $failoverChannel->id,
                'sort' => $index + 1,
                'metadata' => $candidate['parsed'],
            ]);
        }
    }

    /**
     * @param  array<int, array{parsed: array<string, mixed>, stream: array<string, mixed>}>  $selected
     * @param  ?array<int, string>  $manualKeys
     */
    protected function applyToEpisode(Episode $episode, array $selected, ?array $manualKeys): void
    {
        $integrationId = $episode->series->aio_integration_id;

        $this->clearEpisodeFailovers($episode);

        $primary = $selected[0];
        $info = $episode->info ?? [];
        $info['aiostreams'] = $this->aiostreamsData($selected, $manualKeys);

        $episode->update([
            'url' => MediaServerProxyController::generateAioStreamsEpisodeProxyUrl($integrationId, $episode->id),
            'container_extension' => $primary['parsed']['container'] ?? $episode->container_extension,
            'info' => $info,
            // Re-enable an episode that was disabled at creation for being unaired
            // ('scheduled') - it's now playable now that a stream has resolved.
            'enabled' => true,
            'aio_resolution_status' => $this->resolutionStatus($selected, $manualKeys),
            'aio_last_resolved_at' => now(),
        ]);

        foreach (array_slice($selected, 1) as $index => $candidate) {
            // is_aio_failover_clone=true: a lightweight sibling Episode row that only
            // exists so EpisodeFailover has a real row to point at. It carries the same
            // series/season/episode_num as the real episode, so it must never show up
            // anywhere episodes are listed for actual browsing/playback (Xtream API,
            // M3U export, series browse) - Episode's global scope
            // (ExcludeAioFailoverClonesScope) hides it everywhere except
            // failoverEpisodes(), which opts back in explicitly.
            $failoverEpisode = Episode::create([
                'user_id' => $episode->user_id,
                'playlist_id' => $episode->playlist_id,
                'series_id' => $episode->series_id,
                'season_id' => $episode->season_id,
                'title' => $episode->title,
                'episode_num' => $episode->episode_num,
                'season' => $episode->season,
                'import_batch_no' => Str::orderedUuid()->toString(),
                'is_custom' => true,
                'is_aio_failover_clone' => true,
                'info' => ['aiostreams' => ['resolved_url' => $candidate['stream']['url'] ?? null]],
                'container_extension' => $candidate['parsed']['container'] ?? null,
                'aio_item_id' => $episode->aio_item_id,
                'aio_resolution_status' => 'resolved',
                'aio_last_resolved_at' => now(),
            ]);

            $failoverEpisode->update([
                'url' => MediaServerProxyController::generateAioStreamsEpisodeProxyUrl($integrationId, $failoverEpisode->id),
            ]);

            EpisodeFailover::create([
                'user_id' => $episode->user_id,
                'episode_id' => $episode->id,
                'episode_failover_id' => $failoverEpisode->id,
                'sort' => $index + 1,
                'metadata' => $candidate['parsed'],
            ]);
        }
    }

    /**
     * @param  array<int, array{parsed: array<string, mixed>, stream: array<string, mixed>}>  $selected
     * @param  ?array<int, string>  $manualKeys
     * @return array<string, mixed>
     */
    protected function aiostreamsData(array $selected, ?array $manualKeys): array
    {
        $data = [
            'candidates' => array_map(fn (array $c) => $c['parsed'], $selected),
            // Never exposed via API/UI - only MediaServerProxyController's AIOStreams
            // stream endpoints read this, to proxy the bytes without the raw (often
            // debrid-account-scoped) URL ever appearing in a stored/served URL.
            'resolved_url' => $selected[0]['stream']['url'] ?? null,
        ];

        if ($manualKeys) {
            $data['manual_keys'] = array_values($manualKeys);
        }

        return $data;
    }

    /**
     * A manual selection is exactly what the user asked for, so it's always
     * 'resolved'; an automatic one is 'partial' when fewer candidates than the
     * configured failover depth were available.
     *
     * @param  array<int, mixed>  $selected
     * @param  ?array<int, string>  $manualKeys
     */
    protected function resolutionStatus(array $selected, ?array $manualKeys): string
    {
        if ($manualKeys) {
            return 'resolved';
        }

        return count($selected) >= $this->maxCandidates() ? 'resolved' : 'partial';
    }

    protected function maxCandidates(): int
    {
        return app(GeneralSettings::class)->aiostreams_max_failover_candidates ?? 3;
    }

    /**
     * @param  array<int, array{parsed: array<string, mixed>, stream: array<string, mixed>}>  $candidates
     * @return array<int, string>
     */
    protected function streamKeys(array $candidates): array
    {
        return array_map(fn (array $candidate) => self::streamKey($candidate['stream']), $candidates);
    }

    /**
     * Remove the previous failover chain (both the pivot rows and the
     * lightweight sibling rows they point at) before writing a new one, so
     * repeated resolutions don't accumulate stale candidates.
     */
    protected function clearChannelFailovers(Channel $channel): void
    {
        $failoverChannelIds = ChannelFailover::where('channel_id', $channel->id)->pluck('channel_failover_id');

        ChannelFailover::where('channel_id', $channel->id)->delete();

        Channel::withoutGlobalScope(ExcludeAioFailoverClonesScope::class)
            ->whereIn('id', $failoverChannelIds)
            ->where('is_custom', true)
            ->whereNotNull('aio_integration_id')
            ->delete();
    }

    protected function clearEpisodeFailovers(Episode $episode): void
    {
        $failoverEpisodeIds = EpisodeFailover::where('episode_id', $episode->id)->pluck('episode_failover_id');

        EpisodeFailover::where('episode_id', $episode->id)->delete();

        Episode::withoutGlobalScope(ExcludeAioFailoverClonesScope::class)
            ->whereIn('id', $failoverEpisodeIds)
            ->where('is_custom', true)
            ->whereNotNull('aio_item_id')
            ->delete();
    }

    protected function manualCacheKey(Channel|Episode $item): string
    {
        $type = $item instanceof Channel ? 'channel' : 'episode';

        return "aiostreams-manual-candidates:{$item->user_id}:{$type}:{$item->id}";
    }
}
