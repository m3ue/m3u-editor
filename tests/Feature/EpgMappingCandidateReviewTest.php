<?php

use App\Filament\CopilotTools\EpgMappingApplyTool;
use App\Filament\Resources\EpgMaps\Pages\ListEpgMaps;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgMap;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SimilaritySearchService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->epg = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create(['name' => 'Community XMLTV']));
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
    $this->group = Group::factory()->for($this->playlist)->for($this->user)->create();
});

function candidateReviewChannel(string $name): Channel
{
    return Channel::factory()
        ->for(test()->playlist)
        ->for(test()->user)
        ->for(test()->group)
        ->create([
            'name' => $name,
            'title' => $name,
            'stream_id' => str($name)->slug(),
            'epg_map_enabled' => true,
            'is_vod' => false,
        ]);
}

function candidateReviewEpgChannel(array $attributes): EpgChannel
{
    return EpgChannel::factory()
        ->for(test()->epg)
        ->for(test()->user)
        ->create($attributes);
}

it('returns explainable candidates from only the selected source', function () {
    $candidate = candidateReviewEpgChannel([
        'name' => 'ESPNews',
        'display_name' => 'ESPNews',
        'channel_id' => 'espnews.us',
    ]);
    $otherEpg = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create());
    EpgChannel::factory()->for($otherEpg)->for($this->user)->create([
        'name' => 'US Sports ESPN News FHD',
        'display_name' => 'US Sports ESPN News FHD',
    ]);
    $channel = candidateReviewChannel('US | Sports | ESPN News FHD');

    $result = (new SimilaritySearchService)->findEpgChannelCandidates(
        channel: $channel,
        epg: $this->epg,
        removeQualityIndicators: true,
    );

    expect($result['original_name'])->toBe('US | Sports | ESPN News FHD')
        ->and($result['normalized_name'])->toBe('sports espn news')
        ->and($result['automatic_match'])->toBeNull()
        ->and($result['candidates'])->not->toBeEmpty()
        ->and($result['candidates'][0]['epg_channel_id'])->toBe($candidate->id)
        ->and($result['candidates'][0]['matched_value'])->toBe('ESPNews')
        ->and($result['candidates'][0]['normalized_value'])->toBe('espnews')
        ->and($result['candidates'][0]['confidence'])->toBeInt()->toBeGreaterThanOrEqual(40)
        ->and($result['candidates'][0]['reason'])->toContain('normalized');
});

it('keeps exact normalized matches automatic', function () {
    $candidate = candidateReviewEpgChannel([
        'name' => 'ESPN News',
        'display_name' => 'ESPN News',
        'channel_id' => 'espnews.us',
    ]);
    $channel = candidateReviewChannel('ESPN News HD');

    $result = (new SimilaritySearchService)->findEpgChannelCandidates(
        channel: $channel,
        epg: $this->epg,
        removeQualityIndicators: true,
    );

    expect($result['automatic_match']?->id)->toBe($candidate->id)
        ->and($result['candidates'][0]['confidence'])->toBe(100)
        ->and($result['candidates'][0]['reason'])->toContain('Exact normalized');
});

it('ranks database candidates before applying the query bound', function () {
    EpgChannel::factory()
        ->count(250)
        ->for($this->epg)
        ->for($this->user)
        ->sequence(fn ($sequence): array => [
            'name' => "Sports Regional Feed {$sequence->index}",
            'display_name' => "Sports Regional Feed {$sequence->index}",
            'channel_id' => "sports-regional-{$sequence->index}",
        ])
        ->create();
    $candidate = candidateReviewEpgChannel([
        'name' => 'ESPN News',
        'display_name' => 'ESPN News',
        'channel_id' => 'espnews.us',
    ]);
    $channel = candidateReviewChannel('Sports ESPN News');
    $retrievedCandidates = 0;

    Event::listen('eloquent.retrieved: '.EpgChannel::class, function () use (&$retrievedCandidates): void {
        $retrievedCandidates++;
    });

    $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

    expect($retrievedCandidates)->toBe(250)
        ->and($result['candidates'][0]['epg_channel_id'])->toBe($candidate->id);
});

it('does not leak custom quality indicators across calls on a reused service', function () {
    candidateReviewEpgChannel([
        'name' => 'Premium Sports',
        'display_name' => 'Premium Sports',
        'channel_id' => 'premium-sports',
    ]);
    $channel = candidateReviewChannel('Premium Sports');
    $matcher = new SimilaritySearchService;

    $customResult = $matcher->findEpgChannelCandidates(
        channel: $channel,
        epg: $this->epg,
        removeQualityIndicators: true,
        customQualityIndicators: ['premium'],
    );
    $defaultResult = $matcher->findEpgChannelCandidates(
        channel: $channel,
        epg: $this->epg,
        removeQualityIndicators: true,
    );

    expect($customResult['normalized_name'])->toBe('sports')
        ->and($defaultResult['normalized_name'])->toBe('premium sports');
});

it('ranks an alternate name match ahead of weak bounded candidates', function () {
    EpgChannel::factory()
        ->count(250)
        ->for($this->epg)
        ->for($this->user)
        ->sequence(fn ($sequence): array => [
            'name' => "Fox Regional Feed {$sequence->index}",
            'display_name' => "Fox Regional Feed {$sequence->index}",
            'channel_id' => "fox-regional-{$sequence->index}",
        ])
        ->create();
    $candidate = candidateReviewEpgChannel([
        'name' => 'WGHP',
        'display_name' => 'WGHP-DT',
        'channel_id' => 'wghp.us',
        'additional_display_names' => ['Fox Eight Greensboro'],
    ]);
    $channel = candidateReviewChannel('Fox Eight Greensboro');

    $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

    expect($result['candidates'][0]['epg_channel_id'])->toBe($candidate->id)
        ->and($result['candidates'][0]['reason'])->toContain('alternate display name');
});

it('explains reordered words alternate names and station identifiers', function (string $playlistName, array $epgAttributes, string $reason) {
    $candidate = candidateReviewEpgChannel($epgAttributes);
    $channel = candidateReviewChannel($playlistName);

    $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

    expect($result['candidates'][0]['epg_channel_id'])->toBe($candidate->id)
        ->and($result['candidates'][0]['reason'])->toContain($reason);
})->with([
    'reordered words' => [
        'WANE CBS 15',
        ['name' => 'CBS 15 WANE', 'display_name' => 'CBS 15 WANE', 'channel_id' => 'wane.us'],
        'words',
    ],
    'alternate display name' => [
        'Fox Eight Greensboro',
        [
            'name' => 'WGHP',
            'display_name' => 'WGHP-DT',
            'channel_id' => 'wghp.us',
            'additional_display_names' => ['Fox Eight Greensboro'],
        ],
        'alternate display name',
    ],
    'station identifier' => [
        'KOVR',
        ['name' => 'CBS 13 Sacramento', 'display_name' => 'CBS 13', 'channel_id' => 'KOVR-DT'],
        'channel ID',
    ],
]);

it('returns normalized context when no candidate is credible', function () {
    candidateReviewEpgChannel([
        'name' => 'Completely Different Guide Station',
        'display_name' => 'Different Station',
        'channel_id' => 'different.example',
    ]);
    $channel = candidateReviewChannel('ZZQ Unrelated Provider Feed');

    $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

    expect($result['normalized_name'])->toBe('zzq unrelated provider feed')
        ->and($result['candidates'])->toBeEmpty()
        ->and($result['explanation'])->toContain('No candidate');
});

it('shows candidate details and applies only an explicit valid confirmation', function () {
    $this->actingAs($this->user);
    $candidate = candidateReviewEpgChannel([
        'name' => 'ESPNews',
        'display_name' => 'ESPNews',
        'channel_id' => 'espnews.us',
    ]);
    $channel = candidateReviewChannel('US | Sports | ESPN News FHD');
    $map = EpgMap::factory()->create([
        'user_id' => $this->user->id,
        'epg_id' => $this->epg->id,
        'playlist_id' => $this->playlist->id,
        'status' => 'completed',
        'settings' => ['remove_quality_indicators' => true],
    ]);

    Livewire::test(ListEpgMaps::class)
        ->mountAction(TestAction::make('reviewCandidates')->table($map))
        ->assertMountedActionModalSee('Community XMLTV')
        ->assertMountedActionModalSee('US | Sports | ESPN News FHD')
        ->assertMountedActionModalSee('sports espn news')
        ->assertMountedActionModalSee('ESPNews')
        ->fillForm(['mappings' => [$channel->id => $candidate->id]])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($channel->refresh()->epg_channel_id)->toBe($candidate->id);
});

it('rejects cross-source candidates and never replaces an existing explicit mapping', function () {
    $this->actingAs($this->user);
    $existing = candidateReviewEpgChannel([
        'name' => 'Existing',
        'display_name' => 'Existing',
        'channel_id' => 'existing',
    ]);
    $otherEpg = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create());
    $crossSource = EpgChannel::factory()->for($otherEpg)->for($this->user)->create();
    $unmapped = candidateReviewChannel('ESPN News');
    candidateReviewChannel('Existing')->update(['epg_channel_id' => $existing->id]);
    $mappedChannel = Channel::where('name', 'Existing')->firstOrFail();
    $map = EpgMap::factory()->create([
        'user_id' => $this->user->id,
        'epg_id' => $this->epg->id,
        'playlist_id' => $this->playlist->id,
        'status' => 'completed',
        'settings' => [],
    ]);

    Livewire::test(ListEpgMaps::class)
        ->callAction(TestAction::make('reviewCandidates')->table($map), data: [
            'mappings' => [
                $unmapped->id => $crossSource->id,
                $mappedChannel->id => $crossSource->id,
            ],
        ]);

    expect($unmapped->refresh()->epg_channel_id)->toBeNull()
        ->and($mappedChannel->refresh()->epg_channel_id)->toBe($existing->id);
});

it('does not expose candidate review for an epg source owned by another user', function () {
    $this->actingAs($this->user);
    $otherUser = User::factory()->create();
    $otherEpg = Epg::withoutEvents(fn () => Epg::factory()->for($otherUser)->create());
    $map = EpgMap::factory()->create([
        'user_id' => $this->user->id,
        'epg_id' => $otherEpg->id,
        'playlist_id' => $this->playlist->id,
        'status' => 'completed',
        'settings' => [],
    ]);

    Livewire::test(ListEpgMaps::class)
        ->assertActionHidden(TestAction::make('reviewCandidates')->table($map));
});

it('keeps copilot confirmation source scoped and does not overwrite mappings', function () {
    $this->actingAs($this->user);
    $candidate = candidateReviewEpgChannel([
        'name' => 'ESPNews',
        'display_name' => 'ESPNews',
        'channel_id' => 'espnews.us',
    ]);
    $otherEpg = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create());
    $crossSource = EpgChannel::factory()->for($otherEpg)->for($this->user)->create();
    $unmapped = candidateReviewChannel('ESPN News');
    $mapped = candidateReviewChannel('Existing');
    $mapped->update(['epg_channel_id' => $candidate->id]);

    $response = (new EpgMappingApplyTool)->handle(new Request([
        'epg_id' => $this->epg->id,
        'mappings' => json_encode([
            ['channel_id' => $unmapped->id, 'epg_channel_id' => $crossSource->id],
            ['channel_id' => $mapped->id, 'epg_channel_id' => $crossSource->id],
        ], JSON_THROW_ON_ERROR),
    ]));

    expect((string) $response)->toContain('No valid mappings')
        ->and($unmapped->refresh()->epg_channel_id)->toBeNull()
        ->and($mapped->refresh()->epg_channel_id)->toBe($candidate->id);
});
