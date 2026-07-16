<?php

use App\Interfaces\MediaServer;
use App\Services\AIOStreamsService;
use App\Services\EmbyJellyfinService;
use App\Services\LocalMediaService;
use App\Services\PlexService;
use App\Services\WebDavMediaService;

/**
 * Every MediaServer implementation must implement every interface method, or PHP fatals
 * the moment the class is loaded — not caught by a static analyzer, and easy to miss when
 * adding a new interface method (getAvailableTracks/getSubtitleUrl's $preferredLanguage
 * param were both added without updating AIOStreamsService, which broke on class-load for
 * ALL AIOStreams usage, not just the audio/subtitle feature that added them).
 */
it('instantiates every MediaServer implementation without a fatal "must implement" error', function (string $class) {
    expect(is_a($class, MediaServer::class, true))->toBeTrue("{$class} must implement the MediaServer interface");

    $reflection = new ReflectionClass($class);
    expect($reflection->isInstantiable())->toBeTrue("{$class} does not implement every MediaServer interface method");
})->with([
    AIOStreamsService::class,
    EmbyJellyfinService::class,
    LocalMediaService::class,
    PlexService::class,
    WebDavMediaService::class,
]);
