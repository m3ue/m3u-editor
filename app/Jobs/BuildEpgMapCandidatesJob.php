<?php

namespace App\Jobs;

use App\Enums\EpgMapCandidateStatus;
use App\Models\Channel;
use App\Models\EpgMap;
use App\Services\SimilaritySearchService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Build the explainable candidate rows for an EpgMap and store them in
 * epg_map_candidates. Replaces the on-the-fly computation previously done
 * inside the modal review action — for very large playlists the upfront
 * batch pass is dramatically cheaper than recomputing on every page view.
 */
class BuildEpgMapCandidatesJob implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $deleteWhenMissingModels = true;

    public $timeout = 60 * 10;

    public function __construct(public int $epgMapId) {}

    public function handle(): void
    {
        $map = EpgMap::with(['epg', 'playlist', 'user'])->find($this->epgMapId);
        if (! $map || ! $map->epg || ! $map->playlist_id) {
            Log::info("BuildEpgMapCandidatesJob skipped: map {$this->epgMapId} is no longer reviewable.");

            // Synchronous callers may have already set this flag before
            // dispatching — clear it so the UI doesn't spin forever.
            $map?->update(['candidates_building' => false]);

            return;
        }

        $map->update(['candidates_building' => true, 'candidates_progress' => 0]);

        try {
            $this->build($map);
            $map->update([
                'candidates_building' => false,
                'candidates_built_at' => now(),
                'candidates_progress' => 100,
            ]);

            $count = $map->candidates()->count();
            $user = $map->user;

            Notification::make()
                ->success()
                ->title(__('Candidate review ready'))
                ->body(trans_choice(':count candidate built.|:count candidates built.', $count, ['count' => $count]))
                ->broadcast($user)
                ->sendToDatabase($user);
        } catch (\Throwable $e) {
            // Never leave the flag set if the job fails — otherwise the UI
            // would spin forever.
            $map->update(['candidates_building' => false]);

            throw $e;
        }
    }

    private function build(EpgMap $map): void
    {
        $epg = $map->epg;
        $settings = $map->settings ?? [];

        $channels = Channel::query()
            ->where('user_id', $map->user_id)
            ->where('playlist_id', $map->playlist_id)
            ->where('is_vod', false)
            ->where('epg_map_enabled', true)
            ->whereNull('epg_channel_id')
            ->orderBy('name')
            ->get(['id', 'name', 'name_custom', 'title', 'title_custom']);

        if ($channels->isEmpty()) {
            $map->candidates()->delete();

            return;
        }

        $matcher = app(SimilaritySearchService::class);
        $total = $channels->count();

        // Clear any prior run upfront so the UI never shows stale rows during
        // the build, and reset progress so the poll can observe it moving.
        $map->candidates()->delete();

        // Preload matching EPG channels once for the whole batch instead of
        // issuing a LIKE scan per channel — reuses the batch-prefetch API
        // added to SimilaritySearchService so this stays cheap on very
        // large EPG sources.
        $unionTerms = $channels->flatMap(
            fn (Channel $channel): array => $matcher->searchTermsFor(
                channel: $channel,
                cleanedTitle: $matcher->cleanNameForMatching($channel->title_custom ?? $channel->title, $settings),
                cleanedName: $matcher->cleanNameForMatching($channel->name_custom ?? $channel->name, $settings),
            ),
        )->unique()->values()->all();
        $prefetched = $matcher->loadEpgCandidates($epg, $unionTerms);

        $processed = 0;
        $rows = [];

        foreach ($channels as $channel) {
            $cleanedTitle = $matcher->cleanNameForMatching($channel->title_custom ?? $channel->title, $settings);
            $cleanedName = $matcher->cleanNameForMatching($channel->name_custom ?? $channel->name, $settings);
            $result = $matcher->findEpgChannelCandidates(
                channel: $channel,
                epg: $epg,
                removeQualityIndicators: $settings['remove_quality_indicators'] ?? false,
                similarityThreshold: $settings['similarity_threshold'] ?? 70,
                fuzzyMaxDistance: $settings['fuzzy_max_distance'] ?? 25,
                exactMatchDistance: $settings['exact_match_distance'] ?? 8,
                customQualityIndicators: $settings['quality_indicators'] ?? null,
                cleanedTitle: $cleanedTitle,
                cleanedName: $cleanedName,
                prefetchedCandidates: $prefetched,
            );

            $top = $result['candidates'][0] ?? null;
            $alternatives = $top ? array_slice($result['candidates'], 1) : [];

            $rows[] = [
                'epg_map_id' => $map->id,
                'channel_id' => $channel->id,
                'epg_channel_id' => $top['epg_channel_id'] ?? null,
                'original_name' => $result['original_name'],
                'normalized_name' => $result['normalized_name'],
                'top_confidence' => $top['confidence'] ?? 0,
                'top_reason' => $top['reason'] ?? '',
                'top_matched_value' => $top['matched_value'] ?? '',
                'top_normalized_value' => $top['normalized_value'] ?? '',
                'is_exact' => ($top['confidence'] ?? 0) === 100,
                'automatic_match' => (bool) $result['automatic_match'],
                'alternatives' => $alternatives ? json_encode($alternatives, JSON_THROW_ON_ERROR) : null,
                'status' => EpgMapCandidateStatus::Pending->value,
                'applied_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $processed++;

            // Flush every 200 rows and report progress so the polling UI
            // can display a live progress bar (page polls every 3s while
            // candidates_building is true).
            if (count($rows) >= 200) {
                $map->candidates()->insert($rows);
                $rows = [];
                $map->update(['candidates_progress' => round($processed / $total * 100, 1)]);
            }
        }

        if ($rows !== []) {
            $map->candidates()->insert($rows);
        }
    }
}
