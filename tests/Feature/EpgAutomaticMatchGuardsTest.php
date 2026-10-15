<?php

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SimilaritySearchService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->epg = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create());
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
    $this->group = Group::factory()->for($this->playlist)->for($this->user)->create();
});

/**
 * @return array{0: EpgChannel, 1: array<string, mixed>}
 */
function matchAgainstGuide(string $channelName, string $epgName): array
{
    $epgChannel = EpgChannel::factory()->for(test()->epg)->for(test()->user)->create([
        'name' => $epgName,
        'display_name' => $epgName,
        'channel_id' => 'guide.test',
    ]);

    $channel = Channel::factory()
        ->for(test()->playlist)
        ->for(test()->user)
        ->for(test()->group)
        ->create(['name' => $channelName, 'title' => $channelName, 'is_vod' => false]);

    return [$epgChannel, app(SimilaritySearchService::class)->findEpgChannelCandidates($channel, test()->epg)];
}

// Each pair scores well on edit distance, and each was auto-mapped on a real
// playlist before these guards: one character apart, but a different channel.
it('does not auto-match names that differ in a number or a whole word', function (string $channelName, string $epgName) {
    [$epgChannel, $result] = matchAgainstGuide($channelName, $epgName);

    // Still offered for review, just never mapped automatically
    expect($result['automatic_match'])->toBeNull()
        ->and(collect($result['candidates'])->pluck('epg_channel_id'))->toContain($epgChannel->id);
})->with([
    'different slot number' => ['TSN+ 42: NO EVENT', 'TSN+ 12: NO EVENT'],
    'different league' => ['MLS 18: NO EVENT', 'TSN+ 18: NO EVENT'],
    'short acronyms one letter apart' => ['NHL GP 16 | Offline', 'NFL GP 16 | Offline'],
]);

it('still auto-matches added words, typos, plurals and bitrate variants', function (string $channelName, string $epgName) {
    [$epgChannel, $result] = matchAgainstGuide($channelName, $epgName);

    expect($result['automatic_match']?->id)->toBe($epgChannel->id);
})->with([
    'added word' => ['Radio: Dallas Cowboys', 'Dallas Cowboys'],
    'typo' => ['Sportsnet Ontario', 'Sportnet Ontario'],
    'plural' => ['Hallmark Movies & Mysteries', 'Hallmark Mystery'],
    'bitrate variant' => ['U&Alibi HEVC HB (1080p)', 'U&Alibi HEVC LB (1080p)'],
]);
