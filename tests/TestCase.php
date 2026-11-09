<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', env('CACHE_STORE', 'redis'));
        // The cache contract was already resolved with the boot-time default
        // (Redis), and unique-job locks use it. Re-resolve it so those locks
        // follow the test store instead of leaking into the real Redis, where
        // they outlive the run and drop the next run's dispatches.
        $this->app->forgetInstance('cache.store');
        config()->set('session.driver', env('SESSION_DRIVER', 'redis'));
        config()->set('broadcasting.default', 'null');

        // Plugin review staging directories are named after the review id, and
        // every parallel process numbers reviews from 1 in its own database, so
        // each process needs its own staging root.
        if ($token = ParallelTesting::token()) {
            config()->set('plugins.staging_directory', storage_path("app/plugin-staging-{$token}"));

            // Unlike the sqlite_testing connection, Redis isn't split per process -
            // every parallel worker shares the same physical instance, database and
            // key prefix. Real (non-faked) queue/job tests that look at Redis state
            // directly (e.g. RestartQueueCommandTest) can otherwise observe another
            // worker's jobs landing on the same named queue mid-test. Give each
            // worker its own prefix. Both the 'redis' and 'queue' singletons were
            // already resolved (with the old prefix baked into their cached config/
            // connections) before this ran, so forget them outright rather than try
            // to mutate them - the next resolution rebuilds from fresh config.
            config()->set('database.redis.options.prefix', "m3u_editor_test_{$token}_");
            $this->app->forgetInstance('redis');
            $this->app->forgetInstance('redis.connection');
            $this->app->forgetInstance('queue');
        }
    }
}
