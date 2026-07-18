<?php

use App\Enums\EpgMapCandidateStatus;
use App\Filament\CopilotTools\EpgChannelMatcherTool;
use App\Filament\CopilotTools\EpgMappingApplyTool;
use App\Filament\Resources\EpgMaps\Pages\ListEpgMaps;
use App\Filament\Resources\EpgMaps\Pages\ViewEpgMap;
use App\Filament\Resources\EpgMaps\RelationManagers\CandidatesRelationManager;
use App\Jobs\BuildEpgMapCandidatesJob;
use App\Jobs\MapPlaylistChannelsToEpgChunk;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgMap;
use App\Models\Group;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SimilaritySearchService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'broadcasting.default' => 'null',
    ]);
    Queue::fake();
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

it('filters caller supplied candidates to the selected source', function () {
    $otherEpg = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create());
    $crossSource = EpgChannel::factory()->for($otherEpg)->for($this->user)->create([
        'name' => 'Selected Station',
        'display_name' => 'Selected Station',
        'channel_id' => 'selected-station.cross-source',
    ]);
    $selectedSource = candidateReviewEpgChannel([
        'name' => 'Selected Station',
        'display_name' => 'Selected Station',
        'channel_id' => 'selected-station.local',
    ]);
    $channel = candidateReviewChannel('Selected Station');
    $channel->update(['stream_id' => 'provider-unrelated-id']);

    $result = (new SimilaritySearchService)->findEpgChannelCandidates(
        channel: $channel,
        epg: $this->epg,
        prefetchedCandidates: new Collection([$crossSource, $selectedSource]),
    );

    expect($result['automatic_match']?->id)->toBe($selectedSource->id)
        ->and(array_column($result['candidates'], 'epg_channel_id'))->toBe([$selectedSource->id]);
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

it('keeps an unambiguous exact selected source identifier automatic', function () {
    $identifierMatch = candidateReviewEpgChannel([
        'name' => 'Different Guide Name',
        'display_name' => 'Different Guide Name',
        'channel_id' => 'metro-news.provider',
    ]);
    $channel = candidateReviewChannel('Unrelated Playlist Name');
    $channel->update(['stream_id' => 'metro-news.provider']);

    $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

    expect($result['automatic_match']?->id)->toBe($identifierMatch->id)
        ->and($result['decision'])->toBe('exact_identifier')
        ->and($result['candidates'][0]['epg_channel_id'])->toBe($identifierMatch->id);
});

it('gives an unambiguous selected source identifier precedence over a conflicting name', function () {
    $nameMatch = candidateReviewEpgChannel([
        'name' => 'Metro News',
        'display_name' => 'Metro News',
        'channel_id' => 'metro-news.other',
    ]);
    $identifierMatch = candidateReviewEpgChannel([
        'name' => 'Different Guide Name',
        'display_name' => 'Different Guide Name',
        'channel_id' => 'metro-news.provider',
    ]);
    $channel = candidateReviewChannel('Metro News');
    $channel->update(['stream_id' => 'metro-news.provider']);

    $result = (new SimilaritySearchService)->findEpgChannelCandidates(
        channel: $channel,
        epg: $this->epg,
    );

    expect($result['automatic_match']?->id)->toBe($identifierMatch->id)
        ->and($result['automatic_match']?->id)->not->toBe($nameMatch->id)
        ->and($result['decision'])->toBe('identifier_conflict')
        ->and($result['candidates'][0]['epg_channel_id'])->toBe($identifierMatch->id)
        ->and(array_column($result['candidates'], 'epg_channel_id'))->toBe([$identifierMatch->id, $nameMatch->id])
        ->and($result['explanation'])->toContain('identifier');
});

it('uses the configured exact name versus identifier precedence in the canonical matcher', function () {
    $nameMatch = candidateReviewEpgChannel([
        'name' => 'Metro News',
        'display_name' => 'Metro News',
        'channel_id' => 'metro-news.other',
    ]);
    $identifierMatch = candidateReviewEpgChannel([
        'name' => 'Different Guide Name',
        'display_name' => 'Different Guide Name',
        'channel_id' => 'metro-news.provider',
    ]);
    $channel = candidateReviewChannel('Metro News');
    $channel->update(['stream_id' => 'metro-news.provider']);
    $matcher = new SimilaritySearchService;

    $identifierFirst = $matcher->findEpgChannelCandidatesUsingSettings($channel, $this->epg, [
        'prioritize_name_match' => false,
    ]);
    $nameFirst = $matcher->findEpgChannelCandidatesUsingSettings($channel, $this->epg, [
        'prioritize_name_match' => true,
    ]);

    expect($identifierFirst['automatic_match']?->id)->toBe($identifierMatch->id)
        ->and($identifierFirst['decision'])->toBe('identifier_conflict')
        ->and($nameFirst['automatic_match']?->id)->toBe($nameMatch->id)
        ->and($nameFirst['decision'])->toBe('exact_name')
        ->and(array_column($nameFirst['candidates'], 'epg_channel_id'))->toBe([$nameMatch->id, $identifierMatch->id]);
});

it('abstains when an exact identifier resolves to multiple guide rows', function () {
    candidateReviewEpgChannel([
        'name' => 'Metro News East',
        'display_name' => 'Metro News East',
        'channel_id' => 'metro-news.provider',
    ]);
    candidateReviewEpgChannel([
        'name' => 'Metro News West',
        'display_name' => 'Metro News West',
        'channel_id' => 'metro-news.provider',
    ]);
    $channel = candidateReviewChannel('Metro News');
    $channel->update(['stream_id' => 'metro-news.provider']);

    $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

    expect($result['automatic_match'])->toBeNull()
        ->and($result['decision'])->toBe('ambiguous_identifier')
        ->and($result['candidates'])->toHaveCount(2)
        ->and($result['explanation'])->toContain('multiple rows');
});

it('bounds hydration when many rows share an exact identifier', function () {
    EpgChannel::factory()
        ->count(1000)
        ->for($this->epg)
        ->for($this->user)
        ->sequence(fn ($sequence): array => [
            'name' => "Metro News Feed {$sequence->index}",
            'display_name' => "Metro News Feed {$sequence->index}",
            'channel_id' => 'provider-duplicate',
        ])
        ->create();
    $channel = candidateReviewChannel('Metro News');
    $channel->update(['stream_id' => 'provider-duplicate']);
    $retrievedCandidates = 0;

    Event::listen('eloquent.retrieved: '.EpgChannel::class, function () use (&$retrievedCandidates): void {
        $retrievedCandidates++;
    });

    $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

    expect($result['automatic_match'])->toBeNull()
        ->and($result['decision'])->toBe('ambiguous_identifier')
        ->and($retrievedCandidates)->toBeLessThanOrEqual(252);
});

it('abstains from duplicate normalized names in either insertion order', function (bool $reverseOrder) {
    $candidates = [
        [
            'name' => 'Regional News',
            'display_name' => 'Regional News',
            'channel_id' => 'regional-news.east',
        ],
        [
            'name' => 'Regional News',
            'display_name' => 'Regional News',
            'channel_id' => 'regional-news.west',
        ],
    ];

    foreach ($reverseOrder ? array_reverse($candidates) : $candidates as $attributes) {
        candidateReviewEpgChannel($attributes);
    }

    $channel = candidateReviewChannel('Regional News');
    $channel->update(['stream_id' => 'provider-unrelated-id']);

    $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

    expect($result['automatic_match'])->toBeNull()
        ->and($result['decision'])->toBe('ambiguous_name')
        ->and($result['candidates'])->toHaveCount(2)
        ->and($result['candidates'][0]['confidence'])->toBe(100)
        ->and($result['candidates'][1]['confidence'])->toBe(100)
        ->and($result['explanation'])->toContain('multiple identifiers');
})->with([
    'east inserted first' => false,
    'west inserted first' => true,
]);

it('requires the named top two margin for soft automatic matches', function (string $secondName, bool $automatic, string $decision, int $expectedMargin) {
    $top = candidateReviewEpgChannel([
        'name' => 'Alpha Sport Central',
        'display_name' => 'Alpha Sport Central',
        'channel_id' => 'alpha-guide.top',
    ]);
    candidateReviewEpgChannel([
        'name' => $secondName,
        'display_name' => $secondName,
        'channel_id' => 'alpha-guide.second',
    ]);
    $channel = candidateReviewChannel('Alpha Sports Central');
    $channel->update(['stream_id' => 'provider-unrelated-id']);

    $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);
    $actualMargin = $result['candidates'][0]['confidence'] - $result['candidates'][1]['confidence'];

    expect((bool) $result['automatic_match'])->toBe($automatic)
        ->and($result['automatic_match']?->id)->toBe($automatic ? $top->id : null)
        ->and($result['decision'])->toBe($decision)
        ->and($result['candidates'])->toHaveCount(2)
        ->and($actualMargin)->toBe($expectedMargin)
        ->and(SimilaritySearchService::MIN_AUTOMATIC_MATCH_MARGIN)->toBe(10);
})->with([
    'tie' => ['Alpha Sports Centra', false, 'insufficient_margin', 0],
    'below margin' => ['Alpha Sport Centra', false, 'insufficient_margin', 5],
    'at margin' => ['Alpha Spor Centra', true, 'soft_match', 10],
]);

it('keeps an unambiguous callsign automatic in the canonical decision', function () {
    $candidate = candidateReviewEpgChannel([
        'name' => 'CBS Thirteen Sacramento',
        'display_name' => 'CBS Thirteen Sacramento',
        'channel_id' => 'KOVR-DT',
    ]);
    $channel = candidateReviewChannel('US: CBS 13 (KOVR) Stockton HD');
    $channel->update(['stream_id' => 'provider-unrelated-id']);

    $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

    expect($result['automatic_match']?->id)->toBe($candidate->id)
        ->and($result['decision'])->toBe('exact_callsign')
        ->and($result['candidates'][0]['epg_channel_id'])->toBe($candidate->id)
        ->and($result['candidates'][0]['reason'])->toContain('callsign');
});

it('abstains when a callsign matches multiple guide identifiers', function () {
    candidateReviewEpgChannel([
        'name' => 'CBS Thirteen Sacramento',
        'display_name' => 'CBS Thirteen Sacramento',
        'channel_id' => 'KOVR-DT',
    ]);
    candidateReviewEpgChannel([
        'name' => 'Independent Sacramento',
        'display_name' => 'Independent Sacramento',
        'channel_id' => 'KOVR-LD',
    ]);
    $channel = candidateReviewChannel('US: CBS 13 (KOVR) Stockton HD');
    $channel->update(['stream_id' => 'provider-unrelated-id']);

    $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

    expect($result['automatic_match'])->toBeNull()
        ->and($result['decision'])->toBe('ambiguous_callsign')
        ->and($result['candidates'])->toHaveCount(2)
        ->and($result['explanation'])->toContain('multiple identifiers');
});

it('bounds hydration when many rows match one callsign', function () {
    EpgChannel::factory()
        ->count(1000)
        ->for($this->epg)
        ->for($this->user)
        ->sequence(fn ($sequence): array => [
            'name' => "Sacramento Feed {$sequence->index}",
            'display_name' => "Sacramento Feed {$sequence->index}",
            'channel_id' => "KOVR-DT{$sequence->index}",
        ])
        ->create();
    $channel = candidateReviewChannel('Metro News (KOVR)');
    $channel->update(['stream_id' => 'provider-unrelated']);
    $retrievedCandidates = 0;

    Event::listen('eloquent.retrieved: '.EpgChannel::class, function () use (&$retrievedCandidates): void {
        $retrievedCandidates++;
    });

    $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

    expect($result['automatic_match'])->toBeNull()
        ->and($result['decision'])->toBe('ambiguous_callsign')
        ->and($retrievedCandidates)->toBeLessThanOrEqual(252);
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

it('does not hydrate the full source to verify one exact name match', function () {
    $candidate = candidateReviewEpgChannel([
        'name' => 'Metro News',
        'display_name' => 'Metro News',
        'channel_id' => 'metro-news.primary',
    ]);
    EpgChannel::factory()
        ->count(999)
        ->for($this->epg)
        ->for($this->user)
        ->sequence(fn ($sequence): array => [
            'name' => "Metro News Regional Feed {$sequence->index}",
            'display_name' => "Metro News Regional Feed {$sequence->index}",
            'channel_id' => "metro-news-regional-{$sequence->index}",
        ])
        ->create();
    $channel = candidateReviewChannel('Metro News');
    $channel->update(['stream_id' => 'provider-unrelated']);
    $retrievedCandidates = 0;
    $candidateQueries = 0;

    Event::listen('eloquent.retrieved: '.EpgChannel::class, function () use (&$retrievedCandidates): void {
        $retrievedCandidates++;
    });
    Event::listen('Illuminate\\Database\\Events\\QueryExecuted', function ($query) use (&$candidateQueries): void {
        if (str_contains($query->sql, 'epg_channels') && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $candidateQueries++;
        }
    });

    $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

    expect($result['automatic_match']?->id)->toBe($candidate->id)
        ->and($result['decision'])->toBe('exact_name')
        ->and($retrievedCandidates)->toBeLessThanOrEqual(252)
        ->and($candidateQueries)->toBeLessThanOrEqual(3);
});

it('does not hydrate the full source to verify a safe soft margin', function () {
    $candidate = candidateReviewEpgChannel([
        'name' => 'Alpha Sport Central',
        'display_name' => 'Alpha Sport Central',
        'channel_id' => 'alpha-sport.primary',
    ]);
    EpgChannel::factory()
        ->count(999)
        ->for($this->epg)
        ->for($this->user)
        ->sequence(fn ($sequence): array => [
            'name' => "Alpha Sports Unrelated Regional Feed {$sequence->index}",
            'display_name' => "Alpha Sports Unrelated Regional Feed {$sequence->index}",
            'channel_id' => "alpha-sports-regional-{$sequence->index}",
        ])
        ->create();
    $channel = candidateReviewChannel('Alpha Sports Central');
    $channel->update(['stream_id' => 'provider-unrelated']);
    $retrievedCandidates = 0;
    $candidateQueries = 0;

    Event::listen('eloquent.retrieved: '.EpgChannel::class, function () use (&$retrievedCandidates): void {
        $retrievedCandidates++;
    });
    Event::listen('Illuminate\\Database\\Events\\QueryExecuted', function ($query) use (&$candidateQueries): void {
        if (str_contains($query->sql, 'epg_channels') && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $candidateQueries++;
        }
    });

    $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

    expect($result['automatic_match']?->id)->toBe($candidate->id)
        ->and($result['decision'])->toBe('soft_match')
        ->and($retrievedCandidates)->toBeLessThanOrEqual(251)
        ->and($candidateQueries)->toBeLessThanOrEqual(3);
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

it('applies the preferred_local region bonus to candidates and auto-match', function () {
    $candidate = EpgChannel::factory()
        ->for($this->epg)
        ->for($this->user)
        ->create([
            'name' => 'ESPN Sportsburgh Broadcasting Cascadia Cascadia Cascadeoning',
            'display_name' => 'ESPN Sportsburgh Broadcasting Cascadia Cascadia Cascadeoning',
            'channel_id' => 'espn-sportsburg.us',
        ]);

    $channel = Channel::factory()
        ->for($this->playlist)
        ->for($this->user)
        ->for($this->group)
        ->create([
            'name' => 'ESPN Pittsburgh Broadcast Regionals Cascadia Cascadeoning',
            'title' => 'ESPN Pittsburgh Broadcast Regionals Cascadia Cascadeoning',
            'stream_id' => 'espn-pittsburgh',
            'epg_map_enabled' => true,
            'is_vod' => false,
        ]);

    // Without preferred_local the borderline distance (≈14) and cosine < 0.8
    // leave the candidate in the review bucket rather than auto-matched.
    $withoutRegion = (new SimilaritySearchService)->findEpgChannelCandidates(channel: $channel, epg: $this->epg);
    expect($withoutRegion['candidates'])->not->toBeEmpty()
        ->and($withoutRegion['candidates'][0]['reason'])->not->toContain('preferred region')
        ->and($withoutRegion['automatic_match'])->toBeNull();

    // Switch the same EPG to declare a preferred_local that matches the
    // candidate's channel_id substring, then re-run the same scoring pass.
    // The distance bonus should kick the borderline candidate past the
    // automatic-match threshold without altering the underlying rows.
    $this->epg->forceFill(['preferred_local' => 'us'])->save();
    $withRegion = (new SimilaritySearchService)->findEpgChannelCandidates(channel: $channel, epg: $this->epg);

    expect($withRegion['candidates'])->not->toBeEmpty()
        ->and($withRegion['candidates'][0]['reason'])->toContain('preferred region')
        ->and($withRegion['candidates'][0]['epg_channel_id'])->toBe($candidate->id)
        ->and($withRegion['automatic_match']?->id)->toBe($candidate->id);
});

it('preload-batching produces the same top candidates as per-channel queries', function () {
    $candidateA = candidateReviewEpgChannel([
        'name' => 'ESPN News Plus',
        'display_name' => 'ESPN News Plus',
        'channel_id' => 'espnews-plus',
    ]);
    $candidateB = candidateReviewEpgChannel([
        'name' => 'Fox Soccer Channel Premier',
        'display_name' => 'Fox Soccer Channel Premier',
        'channel_id' => 'fox-soccer-prem',
    ]);
    $channelA = candidateReviewChannel('ESPN News Plus HD');
    $channelB = candidateReviewChannel('Fox Soccer Channel Premier HD');

    $matcher = new SimilaritySearchService;
    $directA = $matcher->findEpgChannelCandidates($channelA, $this->epg, removeQualityIndicators: true);
    $directB = $matcher->findEpgChannelCandidates($channelB, $this->epg, removeQualityIndicators: true);

    $unionTerms = collect([$channelA, $channelB])
        ->flatMap(fn (Channel $c): array => $matcher->searchTermsFor(channel: $c, cleanedTitle: $c->title_custom ?? $c->title, cleanedName: $c->name_custom ?? $c->name))
        ->unique()
        ->values()
        ->all();
    $prefetched = $matcher->loadEpgCandidates($this->epg, $unionTerms);

    $batchedA = $matcher->findEpgChannelCandidates(channel: $channelA, epg: $this->epg, removeQualityIndicators: true, prefetchedCandidates: $prefetched);
    $batchedB = $matcher->findEpgChannelCandidates(channel: $channelB, epg: $this->epg, removeQualityIndicators: true, prefetchedCandidates: $prefetched);

    expect($batchedA['candidates'][0]['epg_channel_id'])->toBe($directA['candidates'][0]['epg_channel_id'])
        ->and($batchedB['candidates'][0]['epg_channel_id'])->toBe($directB['candidates'][0]['epg_channel_id'])
        ->and($batchedA['automatic_match']?->id)->toBe($directA['automatic_match']?->id)
        ->and($batchedB['automatic_match']?->id)->toBe($directB['automatic_match']?->id);
});

it('bounds prefetched loading and keeps direct decision parity', function () {
    EpgChannel::factory()
        ->count(260)
        ->for($this->epg)
        ->for($this->user)
        ->sequence(fn ($sequence): array => [
            'name' => "Metro Regional Feed {$sequence->index}",
            'display_name' => "Metro Regional Feed {$sequence->index}",
            'channel_id' => "metro-regional-{$sequence->index}",
        ])
        ->create();
    candidateReviewEpgChannel([
        'name' => 'Metro Regional Prime',
        'display_name' => 'Metro Regional Prime',
        'channel_id' => 'metro-regional-prime',
    ]);
    $channel = candidateReviewChannel('Metro Regional Prime');

    $matcher = new SimilaritySearchService;
    $direct = $matcher->findEpgChannelCandidates($channel, $this->epg);
    $terms = $matcher->searchTermsFor($channel);
    $prefetched = $matcher->loadEpgCandidates($this->epg, $terms);
    $batched = $matcher->findEpgChannelCandidates(
        channel: $channel,
        epg: $this->epg,
        prefetchedCandidates: $prefetched,
    );

    expect($prefetched)->toHaveCount(250)
        ->and($batched['decision'])->toBe($direct['decision'])
        ->and($batched['automatic_match']?->id)->toBe($direct['automatic_match']?->id)
        ->and(array_column($batched['candidates'], 'epg_channel_id'))
        ->toBe(array_column($direct['candidates'], 'epg_channel_id'));
});

describe('Candidate truncation safety (overflow beyond 250)', function () {
    it('finds a normalized duplicate identity after 250 equally relevant rows', function () {
        $primary = candidateReviewEpgChannel([
            'name' => 'Alpha Sports Central',
            'display_name' => 'Alpha Sports Central',
            'channel_id' => 'alpha-sports.primary',
        ]);
        EpgChannel::factory()
            ->count(250)
            ->for($this->epg)
            ->for($this->user)
            ->sequence(fn ($sequence): array => [
                'name' => "Alpha Sports Central Regional Feed {$sequence->index}",
                'display_name' => "Alpha Sports Central Regional Feed {$sequence->index}",
                'channel_id' => "alpha-sports-regional-{$sequence->index}",
            ])
            ->create();
        $duplicate = candidateReviewEpgChannel([
            'name' => 'TV Alpha Sports Central HD',
            'display_name' => 'TV Alpha Sports Central HD',
            'channel_id' => 'alpha-sports.duplicate',
        ]);
        $channel = candidateReviewChannel('Alpha Sports Central');
        $channel->update(['stream_id' => 'provider-unrelated']);

        $result = (new SimilaritySearchService)->findEpgChannelCandidates(
            channel: $channel,
            epg: $this->epg,
            removeQualityIndicators: true,
        );

        expect($result['automatic_match'])->toBeNull()
            ->and($result['decision'])->toBe('ambiguous_name')
            ->and(array_column($result['candidates'], 'epg_channel_id'))->toContain($primary->id, $duplicate->id);
    });

    it('abstains for a close soft runner after 250 equally relevant rows', function () {
        $top = candidateReviewEpgChannel([
            'name' => 'Alpha Sport Central',
            'display_name' => 'Alpha Sport Central',
            'channel_id' => 'alpha-sport.primary',
        ]);
        EpgChannel::factory()
            ->count(250)
            ->for($this->epg)
            ->for($this->user)
            ->sequence(fn ($sequence): array => [
                'name' => "Alpha Sports Unrelated Regional Feed {$sequence->index}",
                'display_name' => "Alpha Sports Unrelated Regional Feed {$sequence->index}",
                'channel_id' => "alpha-sports-regional-{$sequence->index}",
            ])
            ->create();
        candidateReviewEpgChannel([
            'name' => 'Alpha Sports Centra',
            'display_name' => 'Alpha Sports Centra',
            'channel_id' => 'alpha-sports.runner-up',
        ]);
        $channel = candidateReviewChannel('Alpha Sports Central');
        $channel->update(['stream_id' => 'provider-unrelated']);

        $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

        expect($result['automatic_match'])->toBeNull()
            ->and($result['automatic_match']?->id)->not->toBe($top->id)
            ->and($result['decision'])->toBe('insufficient_margin');
    });

    it('abstains when duplicate normalized identity exists beyond the 250-row bound', function () {
        // Create 250 filler channels that have SAME relevance score as target matches
        // by including all search terms ("target", "channel") in their names
        // They will be created FIRST so they get lower IDs and win the tiebreaker
        // Use unrelated filler words to avoid scoring as review candidates
        EpgChannel::factory()
            ->count(250)
            ->for($this->epg)
            ->for($this->user)
            ->sequence(fn ($sequence): array => [
                'name' => "Unrelated Filler Network {$sequence->index}",
                'display_name' => "Unrelated Filler Network {$sequence->index}",
                'channel_id' => "unrelated-filler-{$sequence->index}",
            ])
            ->create();

        // The real match - same normalized name as search target, but created AFTER
        // so it gets higher ID and ranks AFTER the 250 fillers in tiebreaker
        $realMatch = candidateReviewEpgChannel([
            'name' => 'Target Channel',
            'display_name' => 'Target Channel',
            'channel_id' => 'target-channel-real',
        ]);

        // Another channel with same normalized name - also created after
        candidateReviewEpgChannel([
            'name' => 'Target Channel',
            'display_name' => 'Target Channel',
            'channel_id' => 'target-channel-dup',
        ]);

        $channel = candidateReviewChannel('Target Channel');
        $channel->update(['stream_id' => 'provider-unrelated-id']);

        $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

        // Even though the duplicates are beyond the 250-row limit, the decision
        // must still detect the ambiguous_name and abstain
        expect($result['automatic_match'])->toBeNull()
            ->and($result['decision'])->toBe('ambiguous_name')
            ->and($result['candidates'])->toHaveCount(2)
            ->and($result['explanation'])->toContain('multiple identifiers');
    });

    it('abstains when close soft runner-up exists beyond the 250-row bound', function () {
        // Create 250 filler channels with same relevance score, created first
        // Use unrelated filler words to avoid scoring as review candidates
        EpgChannel::factory()
            ->count(250)
            ->for($this->epg)
            ->for($this->user)
            ->sequence(fn ($sequence): array => [
                'name' => "Unrelated Filler Network {$sequence->index}",
                'display_name' => "Unrelated Filler Network {$sequence->index}",
                'channel_id' => "unrelated-filler-{$sequence->index}",
            ])
            ->create();

        // Top candidate - created after fillers, so ranks after them
        $topMatch = candidateReviewEpgChannel([
            'name' => 'Alpha Sport Central',
            'display_name' => 'Alpha Sport Central',
            'channel_id' => 'alpha-guide-top',
        ]);

        // Close runner-up - also created after
        candidateReviewEpgChannel([
            'name' => 'Alpha Sport Centra',  // One char diff - high confidence but below margin
            'display_name' => 'Alpha Sport Centra',
            'channel_id' => 'alpha-guide-second',
        ]);

        $channel = candidateReviewChannel('Alpha Sports Central');
        $channel->update(['stream_id' => 'provider-unrelated-id']);

        $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

        // The runner-up beyond 250 should still force abstention due to insufficient margin
        expect($result['automatic_match'])->toBeNull()
            ->and($result['decision'])->toBe('insufficient_margin')
            ->and($result['candidates'])->toHaveCount(2);
    });

    it('abstains when ambiguous identifier exists beyond the 250-row bound', function () {
        // Create 250 filler channels with same relevance score, created first
        EpgChannel::factory()
            ->count(250)
            ->for($this->epg)
            ->for($this->user)
            ->sequence(fn ($sequence): array => [
                'name' => "Unrelated Filler Network {$sequence->index}",
                'display_name' => "Unrelated Filler Network {$sequence->index}",
                'channel_id' => "unrelated-filler-{$sequence->index}",
            ])
            ->create();

        // Two channels with same identifier - created after fillers
        candidateReviewEpgChannel([
            'name' => 'Metro News East',
            'display_name' => 'Metro News East',
            'channel_id' => 'metro-news.provider',
        ]);
        candidateReviewEpgChannel([
            'name' => 'Metro News West',
            'display_name' => 'Metro News West',
            'channel_id' => 'metro-news.provider',
        ]);

        $channel = candidateReviewChannel('Metro News');
        $channel->update(['stream_id' => 'metro-news.provider']);

        $result = (new SimilaritySearchService)->findEpgChannelCandidates($channel, $this->epg);

        // Even beyond 250, ambiguous_identifier must be detected
        expect($result['automatic_match'])->toBeNull()
            ->and($result['decision'])->toBe('ambiguous_identifier')
            ->and($result['candidates'])->toHaveCount(2)
            ->and($result['explanation'])->toContain('multiple rows');
    });
});

it('uses the same settings decision and ranking in background review and copilot', function () {
    $this->actingAs($this->user);
    $runnerUp = candidateReviewEpgChannel([
        'name' => 'Metro Sports',
        'display_name' => 'Metro Sports',
        'channel_id' => 'metro-sports.sd',
    ]);
    $winner = candidateReviewEpgChannel([
        'name' => 'Metro Sports UHD',
        'display_name' => 'Metro Sports UHD',
        'channel_id' => 'metro-sports.uhd',
    ]);
    $channel = candidateReviewChannel('Metro Sports HD');
    $channel->update([
        'group' => $this->group->name,
        'stream_id' => 'provider-unrelated-id',
    ]);
    $settings = ['remove_quality_indicators' => false];
    $map = EpgMap::factory()->create([
        'user_id' => $this->user->id,
        'epg_id' => $this->epg->id,
        'playlist_id' => $this->playlist->id,
        'status' => 'completed',
        'settings' => $settings,
    ]);

    $batchNo = 'canonical-parity-'.uniqid();
    (new MapPlaylistChannelsToEpgChunk(
        channelIds: [$channel->id],
        epgId: $this->epg->id,
        epgMapId: $map->id,
        settings: $settings,
        batchNo: $batchNo,
        totalChannels: 1,
    ))->handle();
    $backgroundMatch = Job::where('batch_no', $batchNo)->firstOrFail()->payload[0]['epg_channel_id'];

    (new BuildEpgMapCandidatesJob($map->id))->handle();
    $review = $map->candidates()->where('channel_id', $channel->id)->firstOrFail();

    $copilot = (string) (new EpgChannelMatcherTool)->handle(new Request([
        'playlist_id' => $this->playlist->id,
        'group' => $this->group->name,
        'epg_id' => $this->epg->id,
    ]));

    expect($backgroundMatch)->toBe($winner->id)
        ->and($review->automatic_match)->toBeTrue()
        ->and($review->epg_channel_id)->toBe($winner->id)
        ->and($review->alternatives[0]['epg_channel_id'])->toBe($runnerUp->id)
        ->and($copilot)->toContain('AUTOMATIC MATCHES')
        ->and(strpos($copilot, "epg_channel_id: {$winner->id}"))
        ->toBeLessThan(strpos($copilot, "epg_channel_id: {$runnerUp->id}"));
});

it('uses name-first conflict precedence in background review and copilot paths', function () {
    $this->actingAs($this->user);
    $nameMatch = candidateReviewEpgChannel([
        'name' => 'Metro News',
        'display_name' => 'Metro News',
        'channel_id' => 'metro-news.other',
    ]);
    $identifierMatch = candidateReviewEpgChannel([
        'name' => 'Different Guide Name',
        'display_name' => 'Different Guide Name',
        'channel_id' => 'metro-news.provider',
    ]);
    $channel = candidateReviewChannel('Metro News');
    $channel->update([
        'group' => $this->group->name,
        'stream_id' => 'metro-news.provider',
    ]);
    $settings = ['prioritize_name_match' => true];
    $map = EpgMap::factory()->create([
        'user_id' => $this->user->id,
        'epg_id' => $this->epg->id,
        'playlist_id' => $this->playlist->id,
        'status' => 'completed',
        'settings' => $settings,
    ]);
    $direct = (new SimilaritySearchService)->findEpgChannelCandidatesUsingSettings(
        $channel,
        $this->epg,
        $settings,
    );

    $batchNo = 'name-first-parity-'.uniqid();
    (new MapPlaylistChannelsToEpgChunk(
        channelIds: [$channel->id],
        epgId: $this->epg->id,
        epgMapId: $map->id,
        settings: $settings,
        batchNo: $batchNo,
        totalChannels: 1,
    ))->handle();
    $backgroundMatch = Job::where('batch_no', $batchNo)->firstOrFail()->payload[0]['epg_channel_id'];

    (new BuildEpgMapCandidatesJob($map->id))->handle();
    $review = $map->candidates()->where('channel_id', $channel->id)->firstOrFail();
    $copilot = (string) (new EpgChannelMatcherTool)->handle(new Request([
        'playlist_id' => $this->playlist->id,
        'group' => $this->group->name,
        'epg_id' => $this->epg->id,
    ]));

    expect($direct['automatic_match']?->id)->toBe($nameMatch->id)
        ->and($direct['decision'])->toBe('exact_name')
        ->and($backgroundMatch)->toBe($nameMatch->id)
        ->and($review->automatic_match)->toBeTrue()
        ->and($review->epg_channel_id)->toBe($nameMatch->id)
        ->and($review->alternatives[0]['epg_channel_id'])->toBe($identifierMatch->id)
        ->and($copilot)->toContain('AUTOMATIC MATCHES')
        ->and(strpos($copilot, "epg_channel_id: {$nameMatch->id}"))
        ->toBeLessThan(strpos($copilot, "epg_channel_id: {$identifierMatch->id}"));
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

    // Build candidate rows first; the View page hosts the relation manager.
    (new BuildEpgMapCandidatesJob($map->id))->handle();
    $row = $map->candidates()->first();

    Livewire::test(CandidatesRelationManager::class, [
        'ownerRecord' => $map,
        'pageClass' => ViewEpgMap::class,
    ])
        ->loadTable()
        ->assertCanSeeTableRecords([$row])
        ->callAction(TestAction::make('apply')->table($row))
        ->assertNotified();

    expect($channel->refresh()->epg_channel_id)->toBe($candidate->id)
        ->and($row->refresh()->status)->toBe(EpgMapCandidateStatus::Applied);
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

    // Build candidate rows — the cross-source channel must NEVER appear as a
    // candidate, since the build queries against the selected EPG only.
    (new BuildEpgMapCandidatesJob($map->id))->handle();
    $rows = $map->candidates()->get();
    foreach ($rows as $row) {
        expect($row->epg_channel_id)->not->toBe($crossSource->id);
    }

    // Also confirm the existing mapping remains untouched; the build only
    // selected unresolved channels, so the mapped channel has no row.
    expect($rows->firstWhere('channel_id', $mappedChannel->id))->toBeNull();
    expect($mappedChannel->refresh()->epg_channel_id)->toBe($existing->id);
    expect($unmapped->refresh()->epg_channel_id)->toBeNull();
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

    // REVIEW on the list table is hidden because the EPG is owned by another
    // user — relation manager still mounts but its actions are gated by
    // ownerMatchesAuth/ canReview.
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

it('prefetched path selects the identifier row and not the wrong name row when name does not overlap', function () {
    $nameRow = candidateReviewEpgChannel([
        'name' => 'Metro News',
        'display_name' => 'Metro News',
        'channel_id' => 'metro-news.other',
    ]);
    $identifierRow = candidateReviewEpgChannel([
        'name' => 'Something Completely Different',
        'display_name' => 'Something Completely Different',
        'channel_id' => 'provider-777',
    ]);
    $channel = candidateReviewChannel('Metro News');
    $channel->update(['stream_id' => 'provider-777']);

    $matcher = new SimilaritySearchService;
    $direct = $matcher->findEpgChannelCandidates($channel, $this->epg);

    $terms = $matcher->searchTermsFor($channel);
    $prefetched = $matcher->loadEpgCandidates($this->epg, $terms);
    $batched = $matcher->findEpgChannelCandidates(
        channel: $channel,
        epg: $this->epg,
        prefetchedCandidates: $prefetched,
    );

    expect($direct['automatic_match']?->id)->toBe($identifierRow->id)
        ->and($batched['automatic_match']?->id)->toBe($identifierRow->id)
        ->and($batched['automatic_match']?->id)->not->toBe($nameRow->id)
        ->and($batched['decision'])->toBe($direct['decision']);
});

it('prefetched path selects the callsign row when its name does not overlap', function () {
    $nameRow = candidateReviewEpgChannel([
        'name' => 'Metro News',
        'display_name' => 'Metro News',
        'channel_id' => 'metro-news.other',
    ]);
    $callsignRow = candidateReviewEpgChannel([
        'name' => 'Something Completely Different',
        'display_name' => 'Something Completely Different',
        'channel_id' => 'KOVR-DT',
    ]);
    $channel = candidateReviewChannel('Metro News (KOVR)');
    $channel->update(['stream_id' => 'provider-unrelated']);

    $matcher = new SimilaritySearchService;
    $direct = $matcher->findEpgChannelCandidates($channel, $this->epg);

    $prefetched = $matcher->loadEpgCandidates($this->epg, $matcher->searchTermsFor($channel));
    $batched = $matcher->findEpgChannelCandidates(
        channel: $channel,
        epg: $this->epg,
        prefetchedCandidates: $prefetched,
    );

    expect($direct['automatic_match']?->id)->toBe($callsignRow->id)
        ->and($batched['automatic_match']?->id)->toBe($callsignRow->id)
        ->and($batched['automatic_match']?->id)->not->toBe($nameRow->id)
        ->and($batched['decision'])->toBe($direct['decision']);
});

it('prefetched and direct paths produce identical candidate ordering for all evidence types', function () {
    $identifierRow = candidateReviewEpgChannel([
        'name' => 'Different Guide Name',
        'display_name' => 'Different Guide Name',
        'channel_id' => 'cbs-hd.provider',
    ]);
    $callsignRow = candidateReviewEpgChannel([
        'name' => 'CBS Thirteen Sacramento',
        'display_name' => 'CBS Thirteen Sacramento',
        'channel_id' => 'KOVR-DT',
    ]);
    $nameRow = candidateReviewEpgChannel([
        'name' => 'Fox Sports Regional',
        'display_name' => 'Fox Sports Regional',
        'channel_id' => 'fox-sports.regional',
    ]);
    $softRow = candidateReviewEpgChannel([
        'name' => 'Alpha Sport Central',
        'display_name' => 'Alpha Sport Central',
        'channel_id' => 'alpha-guide.top',
    ]);
    $softRunnerUp = candidateReviewEpgChannel([
        'name' => 'Alpha Spor Centra',
        'display_name' => 'Alpha Spor Centra',
        'channel_id' => 'alpha-guide.second',
    ]);

    $idChannel = candidateReviewChannel('CBS HD Stream');
    $idChannel->update(['stream_id' => 'cbs-hd.provider']);
    $callChannel = candidateReviewChannel('US: CBS 13 (KOVR) Stockton HD');
    $callChannel->update(['stream_id' => 'provider-unrelated']);
    $nameChannel = candidateReviewChannel('Fox Sports Regional');
    $nameChannel->update(['stream_id' => 'provider-unrelated']);
    $softChannel = candidateReviewChannel('Alpha Sports Central');
    $softChannel->update(['stream_id' => 'provider-unrelated']);

    $matcher = new SimilaritySearchService;
    $allChannels = [$idChannel, $callChannel, $nameChannel, $softChannel];
    $unionTerms = collect($allChannels)
        ->flatMap(fn (Channel $c): array => $matcher->searchTermsFor(channel: $c, cleanedTitle: $c->title_custom ?? $c->title, cleanedName: $c->name_custom ?? $c->name))
        ->unique()
        ->values()
        ->all();
    $prefetched = $matcher->loadEpgCandidates($this->epg, $unionTerms);

    foreach ($allChannels as $ch) {
        $direct = $matcher->findEpgChannelCandidates($ch, $this->epg);
        $batched = $matcher->findEpgChannelCandidates(
            channel: $ch,
            epg: $this->epg,
            prefetchedCandidates: $prefetched,
        );

        expect($batched['decision'])->toBe($direct['decision'], "Decision mismatch for channel {$ch->name}")
            ->and($batched['automatic_match']?->id)->toBe($direct['automatic_match']?->id, "Auto-match mismatch for channel {$ch->name}")
            ->and(array_column($batched['candidates'], 'epg_channel_id'))->toBe(
                array_column($direct['candidates'], 'epg_channel_id'),
                "Candidate ordering mismatch for channel {$ch->name}",
            );
    }
});
