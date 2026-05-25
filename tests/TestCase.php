<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', env('CACHE_STORE', 'redis'));
        config()->set('session.driver', env('SESSION_DRIVER', 'redis'));
        config()->set('broadcasting.default', 'null');
    }
}
