<?php

it('applies throttle middleware to the watch progress fetch route', function () {
    $route = app('router')->getRoutes()->getByName('watch-progress.fetch');

    expect($route)->not->toBeNull();
    expect($route->middleware())->toContain('throttle:60,1');
});

it('applies throttle middleware to the watch progress update route', function () {
    $route = app('router')->getRoutes()->getByName('watch-progress.update');

    expect($route)->not->toBeNull();
    expect($route->middleware())->toContain('throttle:60,1');
});
