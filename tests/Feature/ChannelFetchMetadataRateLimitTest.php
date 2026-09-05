<?php

use App\Exceptions\XtreamRateLimitedException;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('lets XtreamRateLimitedException propagate instead of swallowing it as a metadata fetch failure', function () {
    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'is_vod' => true,
        'source_id' => 'vod-1',
        'last_metadata_fetch' => null,
    ]);

    $xtream = new class
    {
        public function getVodInfo(string $vodId, int $timeout = 60): array
        {
            throw new XtreamRateLimitedException(now()->addMinutes(15));
        }
    };

    expect(fn () => $channel->fetchMetadata($xtream, refresh: true, skipTmdb: true))
        ->toThrow(XtreamRateLimitedException::class);

    expect($channel->refresh()->last_metadata_fetch)->toBeNull();
});
