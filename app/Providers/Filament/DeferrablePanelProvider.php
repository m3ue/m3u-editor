<?php

namespace App\Providers\Filament;

use App\Filament\Support\LazyPanelRegistry;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\PanelRegistry;

/**
 * Registers the panel like Filament's PanelProvider, but hands it to
 * LazyPanelRegistry as a closure so background processes can skip building it
 * until first use. See LazyPanelRegistry for which processes defer.
 */
abstract class DeferrablePanelProvider extends PanelProvider
{
    public function register(): void
    {
        $this->app->resolving(PanelRegistry::class, function (PanelRegistry $registry): void {
            $buildPanel = fn (): Panel => $this->panel(Panel::make());

            if ($registry instanceof LazyPanelRegistry) {
                $registry->registerLazily($buildPanel);

                return;
            }

            $registry->register($buildPanel());
        });
    }
}
