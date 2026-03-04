<?php

use App\Models\NetworkContent;

it('parses various duration formats to seconds', function () {
    $model = new NetworkContent;

    // Use Closure::bind to call the protected method without using Reflection::setAccessible
    $call = \Closure::bind(function ($d) {
        return $this->parseDuration($d);
    }, $model, get_class($model));

    expect($call(3600))->toBe(3600);
    expect($call('3600'))->toBe(3600);
    expect($call('01:02:03'))->toBe(3723);
    expect($call('02:03'))->toBe(123);
    expect($call('invalid'))->toBe(0);
});
