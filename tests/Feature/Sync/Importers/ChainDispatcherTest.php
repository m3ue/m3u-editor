<?php

use App\Jobs\CreateBackup;
use App\Models\Playlist;
use App\Models\User;
use App\Sync\Importers\Support\ChainDispatcher;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();
});

function makeChainPlaylist(): Playlist
{
    $user = User::factory()->create();

    return Playlist::factory()->for($user)->create();
}

it('chains the supplied jobs in order', function () {
    $playlist = makeChainPlaylist();

    (new ChainDispatcher)->dispatch([new CreateBackup(includeFiles: false)], $playlist);

    Bus::assertChained([CreateBackup::class]);
});

it('chains multiple jobs preserving order', function () {
    $playlist = makeChainPlaylist();

    (new ChainDispatcher)->dispatch([
        new CreateBackup(includeFiles: false),
        new CreateBackup(includeFiles: true),
    ], $playlist);

    Bus::assertChained([CreateBackup::class, CreateBackup::class]);
});
