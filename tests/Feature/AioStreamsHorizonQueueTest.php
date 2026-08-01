<?php

/**
 * Regression guard: ResolveAioStreamsChannel/ResolveAioStreamsEpisode dispatch onto the
 * 'aiostreams-resolve' queue, but Horizon only spins up workers for queues named in its
 * own config — a job dispatched to a queue no supervisor listens on just sits in Redis
 * forever. This went unnoticed for a while since Bus::fake()/Queue::fake() in the job
 * tests never touch a real worker. See PLAN_AIOSTREAMS.md.
 */
it('has a Horizon supervisor listening on the aiostreams-resolve queue', function () {
    $queues = collect(config('horizon.defaults'))
        ->flatMap(fn (array $supervisor) => $supervisor['queue'] ?? []);

    expect($queues)->toContain('aiostreams-resolve');
});
