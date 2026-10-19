<?php

use App\Plugins\PluginManager;
use App\Services\DvrSchedulerService;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The frequent checks run inside schedule:run instead of booting a separate
 * `php artisan` process each, which was nearly all of their CPU.
 */
function scheduledEventNamed(string $name): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => $event->description === $name);
}

it('runs the frequent checks in the scheduler process with a short overlap lock', function (string $command, string $expression) {
    $event = scheduledEventNamed($command);

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe($expression)
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(15);
})->with([
    ['app:refresh-playlist', '* * * * *'],
    ['app:refresh-media-server-integrations', '* * * * *'],
    ['app:refresh-epg', '* * * * *'],
    ['app:epg-cache-health-check', '*/15 * * * *'],
    ['app:run-scheduled-backups', '*/2 * * * *'],
    ['plugins:recover-stale-runs', '* * * * *'],
    ['app:dvr-scheduler-tick', '* * * * *'],
    ['m3u-proxy:register-webhook', '*/5 * * * *'],
    ['app:refresh-playlist-profiles', '*/15 * * * *'],
]);

it('no longer starts a separate process for the frequent checks', function () {
    $spawned = collect(app(Schedule::class)->events())
        ->reject(fn (Event $event) => $event instanceof CallbackEvent)
        ->map(fn (Event $event) => $event->command)
        ->filter(fn (string $command) => str_ends_with($command, ' app:refresh-playlist')
            || str_ends_with($command, ' app:refresh-epg')
            || str_ends_with($command, ' app:dvr-scheduler-tick'));

    expect($spawned)->toBeEmpty();
});

it('keeps scheduled plugin invocations in their own process', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_ends_with((string) $event->command, ' plugins:run-scheduled'));

    expect($event)->not->toBeNull()
        ->and($event)->not->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('runs the command when the event fires', function () {
    $this->mock(DvrSchedulerService::class)->shouldReceive('tick')->once();

    $event = scheduledEventNamed('app:dvr-scheduler-tick');
    $event->run(app());

    expect($event->exitCode)->toBe(0);
});

it('surfaces a failing check to schedule:run instead of swallowing it', function () {
    $this->mock(PluginManager::class)->shouldReceive('recoverStaleRuns')->andThrow(new RuntimeException('plugin store unavailable'));

    $event = scheduledEventNamed('plugins:recover-stale-runs');

    expect(fn () => $event->run(app()))->toThrow(RuntimeException::class, 'plugin store unavailable')
        ->and($event->exitCode)->toBe(1);
});
