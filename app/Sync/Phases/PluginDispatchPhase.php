<?php

namespace App\Sync\Phases;

use App\Models\Playlist;
use App\Models\SyncRun;
use App\Plugins\PluginHookDispatcher;
use App\Sync\Contracts\BatchablePhase;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Fire the `playlist.synced` plugin hook so any installed plugins can react
 * to a successful sync.
 *
 * Implements {@see BatchablePhase}: the orchestrator may collect the
 * per-plugin invocation jobs across sibling parallel-group phases and
 * dispatch them as a single `Bus::batch([...])`. The standalone `execute()`
 * path delegates to {@see PluginHookDispatcher::dispatch()} so direct
 * `$phase->run()` callers (and the existing tests that mock the dispatcher)
 * keep working unchanged.
 */
class PluginDispatchPhase extends AbstractPhase implements BatchablePhase
{
    public const HOOK = 'playlist.synced';

    public function __construct(
        private readonly PluginHookDispatcher $dispatcher,
    ) {}

    public static function slug(): string
    {
        return 'plugin_dispatch';
    }

    /**
     * Always run on success — the dispatcher itself decides whether any
     * plugin is subscribed and is cheap to call when none are.
     */
    public function shouldRun(Playlist $playlist): bool
    {
        return true;
    }

    protected function execute(SyncRun $run, Playlist $playlist, array $context): ?array
    {
        $this->dispatcher->dispatch(self::HOOK, $this->payload($playlist), $this->options($playlist));

        return null;
    }

    /**
     * @return array<int, ShouldQueue>
     */
    public function batchJobs(SyncRun $run, Playlist $playlist, array $context = []): array
    {
        return $this->dispatcher->buildJobs(self::HOOK, $this->payload($playlist), $this->options($playlist));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Playlist $playlist): array
    {
        return [
            'playlist_id' => $playlist->id,
            'user_id' => $playlist->user_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(Playlist $playlist): array
    {
        return [
            'dry_run' => false,
            'user_id' => $playlist->user_id,
        ];
    }
}
