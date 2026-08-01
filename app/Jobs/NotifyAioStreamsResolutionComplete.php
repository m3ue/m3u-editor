<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Polls the resolution status of a set of AIOStreams-backed channels/episodes
 * and reports a single summary notification once they're done (or after a
 * capped number of polls, whichever comes first) — e.g. "8 episodes fetched
 * | 4 resolved | 2 failed". Dispatched by every AIOStreams resolution trigger
 * (add to library, rescan, the hourly pending-candidates sweep) so users
 * aren't left guessing whether a sync ever finished.
 *
 * Deliberately does not use Bus::batch() here: ResolveAioStreamsChannel/Episode
 * are ShouldBeUnique, and that dedupe lock is only honored on the normal
 * ::dispatch() path — jobs pushed via a batch skip it entirely, which would
 * silently defeat the debounce protection those jobs rely on for ban-avoidance.
 * Polling for status instead keeps the individual ::dispatch() calls untouched.
 */
class NotifyAioStreamsResolutionComplete implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Cap on how many times this job will reschedule itself while waiting for
     * still-pending items. At 15s between polls, this is a 5 minute ceiling —
     * comfortably past the ~1 minute empty-result retry budget each resolve
     * job gets, even for a batch throttled by the AIOStreams rate limit.
     */
    private const MAX_ATTEMPTS = 20;

    /**
     * @param  array<int>  $channelIds
     * @param  array<int>  $episodeIds
     */
    public function __construct(
        public array $channelIds,
        public array $episodeIds,
        public ?int $userId,
        public string $context,
        public int $attempt = 1,
    ) {}

    public function handle(): void
    {
        if (! $this->userId || (empty($this->channelIds) && empty($this->episodeIds))) {
            return;
        }

        $channelCounts = $this->tally(Channel::class, $this->channelIds);
        $episodeCounts = $this->tally(Episode::class, $this->episodeIds);

        $stillPending = $channelCounts['pending'] + $episodeCounts['pending'];

        if ($stillPending > 0 && $this->attempt < self::MAX_ATTEMPTS) {
            self::dispatch($this->channelIds, $this->episodeIds, $this->userId, $this->context, $this->attempt + 1)
                ->delay(now()->addSeconds(15));

            return;
        }

        $total = $channelCounts['total'] + $episodeCounts['total'];

        if ($total === 0) {
            return;
        }

        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $resolved = $channelCounts['resolved'] + $episodeCounts['resolved'];
        $failed = $channelCounts['failed'] + $episodeCounts['failed'];

        $body = __(':total :unit fetched | :resolved resolved | :failed failed', [
            'total' => $total,
            'unit' => $this->unitLabel($channelCounts['total'], $episodeCounts['total']),
            'resolved' => $resolved,
            'failed' => $failed,
        ]);

        // Only reachable by hitting MAX_ATTEMPTS above with something still 'pending' —
        // say so explicitly rather than letting it silently vanish from the count
        // (resolved + failed wouldn't otherwise add up to total).
        if ($stillPending > 0) {
            $body .= ' | '.__(':count still pending', ['count' => $stillPending]);
        }

        $notification = Notification::make()
            ->title(__('AIOStreams sync complete: :context', ['context' => $this->context]))
            ->body($body);

        if ($failed > 0 && $resolved === 0) {
            $notification->warning();
        } else {
            $notification->success();
        }

        $notification->broadcast($user)->sendToDatabase($user);
    }

    /**
     * @param  class-string<Channel|Episode>  $modelClass
     * @param  array<int>  $ids
     * @return array{total: int, resolved: int, failed: int, pending: int}
     */
    private function tally(string $modelClass, array $ids): array
    {
        if (empty($ids)) {
            return ['total' => 0, 'resolved' => 0, 'failed' => 0, 'pending' => 0];
        }

        $counts = $modelClass::whereIn('id', $ids)
            ->selectRaw('aio_resolution_status, count(*) as c')
            ->groupBy('aio_resolution_status')
            ->pluck('c', 'aio_resolution_status');

        return [
            'total' => (int) $counts->sum(),
            'resolved' => (int) (($counts['resolved'] ?? 0) + ($counts['partial'] ?? 0)),
            'failed' => (int) ($counts['failed'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
        ];
    }

    private function unitLabel(int $channelTotal, int $episodeTotal): string
    {
        if ($channelTotal > 0 && $episodeTotal > 0) {
            return __('item(s)');
        }

        if ($episodeTotal > 0) {
            return $episodeTotal === 1 ? __('episode') : __('episodes');
        }

        return $channelTotal === 1 ? __('movie') : __('movies');
    }
}
