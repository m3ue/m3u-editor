<?php

namespace App\Sync\Phases;

use App\Models\Playlist;
use App\Models\SyncRun;
use App\Plugins\PluginHookDispatcher;

/**
 * Fire the `playlist.synced` plugin hook so any installed plugins can react
 * to a successful sync. Runs synchronously inside the orchestrator since the
 * dispatcher itself queues plugin work.
 */
class PluginDispatchPhase extends AbstractPhase
{
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
        $this->dispatcher->dispatch('playlist.synced', [
            'playlist_id' => $playlist->id,
            'user_id' => $playlist->user_id,
        ], [
            'dry_run' => false,
            'user_id' => $playlist->user_id,
        ]);

        return null;
    }
}
