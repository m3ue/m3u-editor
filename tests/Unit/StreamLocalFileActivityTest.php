<?php

use App\Http\Controllers\Concerns\StreamLocalFile;

it('runs stream lifecycle callbacks around a successful response body', function () {
    $path = tempnam(sys_get_temp_dir(), 'stream-local-file-');
    file_put_contents($path, 'cache-bytes');
    $events = [];

    $response = StreamLocalFile::serve(
        $path,
        11,
        'video/mp4',
        'cache.mp4',
        null,
        onStart: function () use (&$events): void {
            $events[] = 'start';
        },
        onProgress: function () use (&$events): void {
            $events[] = 'progress';
        },
        onFinish: function () use (&$events): void {
            $events[] = 'finish';
        },
    );

    ob_start();
    ($response->getCallback())();
    ob_end_clean();
    unlink($path);

    expect($events)->toBe(['start', 'progress', 'finish']);
});

it('calls finish when the file cannot be opened', function () {
    $events = [];

    $response = StreamLocalFile::serve(
        '/nonexistent/managed-cache-file.mp4',
        100,
        'video/mp4',
        'cache.mp4',
        null,
        onStart: function () use (&$events): void {
            $events[] = 'start';
        },
        onFinish: function () use (&$events): void {
            $events[] = 'finish';
        },
    );

    ob_start();
    ($response->getCallback())();
    ob_end_clean();

    expect($events)->toBe(['finish']);
});

it('calls finish for an out-of-bounds range', function () {
    $path = tempnam(sys_get_temp_dir(), 'stream-local-file-');
    file_put_contents($path, 'cache-bytes');
    $events = [];

    $response = StreamLocalFile::serve(
        $path,
        11,
        'video/mp4',
        'cache.mp4',
        'bytes=100-200',
        onStart: function () use (&$events): void {
            $events[] = 'start';
        },
        onFinish: function () use (&$events): void {
            $events[] = 'finish';
        },
    );

    ob_start();
    ($response->getCallback())();
    ob_end_clean();
    unlink($path);

    expect($events)->toBe(['finish']);
});
