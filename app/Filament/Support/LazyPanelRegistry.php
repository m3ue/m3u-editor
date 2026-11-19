<?php

namespace App\Filament\Support;

use Closure;
use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Contracts\Foundation\Application;

/**
 * Builds Filament panels on first use in long-running background processes.
 *
 * FilamentServiceProvider resolves the panel registry while booting, so every PHP
 * process runs each panel's panel() method, which loads every Resource, Page and
 * Widget class (about 26 MB per process). Horizon, Reverb and the scheduler keep
 * many processes alive that never render a panel, so in those processes the panels
 * are held as closures and built the first time something asks for one (for example
 * a queued notification that links to a Resource). Web requests and every other
 * Artisan command build panels at boot exactly as before.
 *
 * All of Filament's panel lookups go through get(), getDefault() and all().
 */
class LazyPanelRegistry extends PanelRegistry
{
    /**
     * Artisan commands that start long-running background processes.
     *
     * Panel URLs keep working in these processes because the container caches
     * routes (`php artisan optimize`). Without a route cache, loading Filament's
     * panel routes asks for every panel, so they are built at boot as before.
     *
     * @var list<string>
     */
    public const DEFERRED_COMMANDS = [
        'horizon',
        'horizon:supervisor',
        'horizon:work',
        'queue:work',
        'reverb:start',
        'schedule:run',
        'network:broadcast',
    ];

    /**
     * @var list<Closure(): Panel>
     */
    private array $pendingPanels = [];

    public function __construct(private readonly bool $defersPanels = false) {}

    public static function shouldDeferPanels(Application $app): bool
    {
        if (! $app->runningInConsole()) {
            return false;
        }

        return in_array($_SERVER['argv'][1] ?? null, self::DEFERRED_COMMANDS, true);
    }

    /**
     * @param  Closure(): Panel  $buildPanel
     */
    public function registerLazily(Closure $buildPanel): void
    {
        if (! $this->defersPanels) {
            $this->register($buildPanel());

            return;
        }

        $this->pendingPanels[] = $buildPanel;
    }

    public function hasPendingPanels(): bool
    {
        return $this->pendingPanels !== [];
    }

    public function getDefault(): Panel
    {
        $this->registerPendingPanels();

        return parent::getDefault();
    }

    public function get(?string $id = null, bool $isStrict = true): ?Panel
    {
        $this->registerPendingPanels();

        return parent::get($id, $isStrict);
    }

    /**
     * @return array<string, Panel>
     */
    public function all(): array
    {
        $this->registerPendingPanels();

        return parent::all();
    }

    /**
     * Each closure is removed before it runs, so a lookup made while a panel is
     * being built (e.g. a page asking for the default panel) doesn't build it twice.
     */
    private function registerPendingPanels(): void
    {
        while ($this->pendingPanels !== []) {
            $buildPanel = array_shift($this->pendingPanels);

            $this->register($buildPanel());
        }
    }
}
