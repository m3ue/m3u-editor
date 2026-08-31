<?php

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;

/**
 * Guards against the Xtream API docs regression where the `#[QueryParameter]`
 * annotations on XtreamApiController::handle() were removed, leaving the
 * `/player_api.php` operation in the Scramble docs with no testable inputs
 * (most visibly, no `action` field).
 */
it('documents the player_api.php query parameters in the OpenAPI spec', function () {
    /** @var Generator $generator */
    $generator = app(Generator::class);

    $document = $generator(Scramble::getGeneratorConfig('default'));

    $parameters = collect($document['paths']['/player_api.php']['get']['parameters'] ?? [])
        ->keyBy('name');

    expect($parameters)->toHaveKeys(['username', 'password', 'action']);

    expect($parameters['username']['required'])->toBeTrue();
    expect($parameters['password']['required'])->toBeTrue();

    expect($parameters['action']['required'] ?? false)->toBeFalse();
    expect($parameters['action']['schema']['default'] ?? null)->toBe('panel');
});
