<?php

use App\Exceptions\MediaServerException;

it('is instantiable and extends Exception', function () {
    $exception = new MediaServerException('boom');

    expect($exception)
        ->toBeInstanceOf(MediaServerException::class)
        ->toBeInstanceOf(Exception::class);

    expect($exception->getMessage())->toBe('boom');
});

it('is throwable and catchable as itself', function () {
    expect(fn () => throw new MediaServerException('kaboom'))
        ->toThrow(MediaServerException::class, 'kaboom');
});
