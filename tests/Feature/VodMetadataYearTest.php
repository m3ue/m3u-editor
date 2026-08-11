<?php

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;

function vodMetadataProvider(array $payload): object
{
    return new class($payload)
    {
        public function __construct(private readonly array $payload) {}

        public function getVodInfo(string $vodId, int $timeout = 60): array
        {
            return $this->payload;
        }
    };
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('preserves a stored VOD year when provider metadata has another release date', function () {
    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'is_vod' => true,
        'source_id' => 'vod-1',
        'year' => 2020,
    ]);

    $result = $channel->fetchMetadata(
        vodMetadataProvider(['info' => ['release_date' => '2024-05-17']]),
        refresh: true,
        skipTmdb: true,
    );

    expect($result)->toBeTrue()
        ->and((int) $channel->refresh()->year)->toBe(2020);
});

it('derives a missing VOD year from provider release metadata', function () {
    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'is_vod' => true,
        'source_id' => 'vod-1',
        'year' => null,
    ]);

    $result = $channel->fetchMetadata(
        vodMetadataProvider(['info' => ['releasedate' => '2024-05-17']]),
        refresh: true,
        skipTmdb: true,
    );

    expect($result)->toBeTrue()
        ->and((int) $channel->refresh()->year)->toBe(2024)
        ->and($channel->info['release_date'])->toBe('2024-05-17');
});
