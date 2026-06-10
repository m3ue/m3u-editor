<?php

use App\Services\PlaylistService;

it('strips malformed utf-8 bytes before making filesystem safe paths', function () {
    $safe = PlaylistService::makeFilesystemSafe("Movie \xB1: Name", 'dash');

    expect($safe)
        ->toBe('Movie - Name')
        ->and(mb_check_encoding($safe, 'UTF-8'))->toBeTrue();
});
