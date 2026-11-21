<?php

use Illuminate\Support\Facades\File;

/**
 * public/m3u-tv is a gitignored Flutter build output, so CI has no
 * index.html. Create a placeholder only when it's missing, and remove only
 * what the test created.
 */
beforeEach(function () {
    $this->m3uTvIndexPath = public_path('m3u-tv/index.html');
    $this->m3uTvCreatedDirectory = ! File::isDirectory(dirname($this->m3uTvIndexPath));
    $this->m3uTvCreatedIndex = ! File::exists($this->m3uTvIndexPath);

    if ($this->m3uTvCreatedIndex) {
        File::ensureDirectoryExists(dirname($this->m3uTvIndexPath));
        File::put($this->m3uTvIndexPath, '<!doctype html><title>M3U TV</title>');
    }
});

afterEach(function () {
    if ($this->m3uTvCreatedDirectory) {
        File::deleteDirectory(dirname($this->m3uTvIndexPath));
    } elseif ($this->m3uTvCreatedIndex) {
        File::delete($this->m3uTvIndexPath);
    }
});

it('serves the M3U TV web app entry with cross-origin isolation headers', function () {
    $this->get('/m3u-tv/')
        ->assertOk()
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeader('Cross-Origin-Embedder-Policy', 'credentialless');
});
