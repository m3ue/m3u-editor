<?php

use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Support\LazyPanelRegistry;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;

/**
 * Background processes (Horizon, Reverb, the scheduler) hold Filament panels as
 * closures and build them on first use, instead of loading every Resource class
 * at boot. Everything else builds them at boot as before.
 */
function lazyPanelRegistryCountingBuilder(string $id, int &$builds, bool $isDefault = false): Closure
{
    return function () use ($id, &$builds, $isDefault): Panel {
        $builds++;

        return Panel::make()->id($id)->default($isDefault);
    };
}

function lazyPanelRegistryWithArgv(?string $command, Closure $callback): mixed
{
    $originalArgv = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = $command === null ? ['artisan'] : ['artisan', $command];

    try {
        return $callback();
    } finally {
        $_SERVER['argv'] = $originalArgv;
    }
}

it('builds panels right away when not deferring', function () {
    $builds = 0;
    $registry = new LazyPanelRegistry(defersPanels: false);

    $registry->registerLazily(lazyPanelRegistryCountingBuilder('first', $builds));

    expect($builds)->toBe(1)
        ->and($registry->hasPendingPanels())->toBeFalse()
        ->and($registry->panels)->toHaveKey('first');
});

it('holds panels until the first lookup when deferring', function (Closure $lookup) {
    $builds = 0;
    $registry = new LazyPanelRegistry(defersPanels: true);

    $registry->registerLazily(lazyPanelRegistryCountingBuilder('first', $builds, isDefault: true));
    $registry->registerLazily(lazyPanelRegistryCountingBuilder('second', $builds));

    expect($builds)->toBe(0)
        ->and($registry->hasPendingPanels())->toBeTrue();

    $lookup($registry);

    expect($builds)->toBe(2)
        ->and($registry->hasPendingPanels())->toBeFalse()
        ->and(array_keys($registry->panels))->toBe(['first', 'second']);

    $lookup($registry);

    expect($builds)->toBe(2);
})->with([
    'get' => [fn (LazyPanelRegistry $registry) => $registry->get('second')],
    'getDefault' => [fn (LazyPanelRegistry $registry) => $registry->getDefault()],
    'all' => [fn (LazyPanelRegistry $registry) => $registry->all()],
]);

it('builds each panel once when a lookup happens while a panel is being built', function () {
    $builds = 0;
    $registry = new LazyPanelRegistry(defersPanels: true);

    $registry->registerLazily(function () use ($registry, &$builds): Panel {
        $builds++;
        $registry->all();

        return Panel::make()->id('first')->default();
    });
    $registry->registerLazily(lazyPanelRegistryCountingBuilder('second', $builds));

    expect($registry->getDefault()->getId())->toBe('first')
        ->and($builds)->toBe(2)
        ->and($registry->all())->toHaveCount(2);
});

it('defers only for long-running background commands', function (?string $command, bool $defers) {
    expect(lazyPanelRegistryWithArgv($command, fn () => LazyPanelRegistry::shouldDeferPanels(app())))->toBe($defers);
})->with([
    ['horizon', true],
    ['horizon:supervisor', true],
    ['horizon:work', true],
    ['queue:work', true],
    ['reverb:start', true],
    ['schedule:run', true],
    ['network:broadcast', true],
    ['filament:optimize', false],
    ['route:cache', false],
    ['app:refresh-playlist', false],
    [null, false],
]);

it('never defers outside the console', function () {
    (fn () => $this->isRunningInConsole = false)->call(app());

    expect(lazyPanelRegistryWithArgv('horizon:work', fn () => LazyPanelRegistry::shouldDeferPanels(app())))->toBeFalse();
});

it('builds the app panels at boot outside background commands', function () {
    $registry = app(PanelRegistry::class);

    expect($registry)->toBeInstanceOf(LazyPanelRegistry::class)
        ->and($registry->hasPendingPanels())->toBeFalse()
        ->and(array_keys($registry->panels))->toContain('admin', 'playlist');
});

it('builds the deferred app panels on first use, so Resource URLs still resolve', function () {
    app()->forgetInstance(PanelRegistry::class);
    app()->singleton(PanelRegistry::class, fn (): LazyPanelRegistry => new LazyPanelRegistry(defersPanels: true));

    $registry = app(PanelRegistry::class);

    expect($registry->hasPendingPanels())->toBeTrue()
        ->and($registry->panels)->toBeEmpty();

    expect(PlaylistResource::getUrl('index'))->toEndWith('/playlists')
        ->and($registry->hasPendingPanels())->toBeFalse()
        ->and(Filament::getDefaultPanel()->getId())->toBe('admin')
        ->and(Filament::getPanel('playlist')?->getId())->toBe('playlist');
});
