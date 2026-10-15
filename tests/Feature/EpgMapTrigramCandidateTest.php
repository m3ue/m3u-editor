<?php

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SimilaritySearchService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->epg = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create());
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
    $this->group = Group::factory()->for($this->playlist)->for($this->user)->create();
});

function trigramChannel(string $name): Channel
{
    return Channel::factory()
        ->for(test()->playlist)
        ->for(test()->user)
        ->for(test()->group)
        ->create([
            'name' => $name,
            'title' => $name,
            'epg_map_enabled' => true,
            'is_vod' => false,
        ]);
}

function trigramEpgChannel(array $attributes): EpgChannel
{
    return EpgChannel::factory()
        ->for(test()->epg)
        ->for(test()->user)
        ->create($attributes);
}

it('widens the candidate pool on Postgres via pg_trgm for typos with no shared literal substring', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires the pgsql connection (DB_CONNECTION=pgsql) to exercise pg_trgm.');
    }

    // No migration installs pg_trgm anymore - it's provisioned out-of-band
    // (docker/8.4/db-init.sh for the embedded Postgres image, or manually
    // for an external one) and SimilaritySearchService detects it at
    // runtime. The test provisions its own precondition rather than
    // depending on whatever set up the test database.
    DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

    // Single-word name so the only search term is the whole 9-letter word;
    // a two-letter transposition breaks any literal LIKE substring match,
    // but pg_trgm similarity() still sees the two strings as close.
    $match = trigramEpgChannel([
        'name' => 'Soprtsnet',
        'display_name' => 'Soprtsnet',
        'channel_id' => 'soprtsnet.us',
    ]);
    $channel = trigramChannel('Sportsnet');

    // Trigram matching is opt-in per EpgMap (settings.trigram_matching_enabled)
    // since it measurably slows down large EPG imports - enable it explicitly
    // to exercise the widened path.
    $result = app(SimilaritySearchService::class)->findEpgChannelCandidates(
        $channel,
        $this->epg,
        trigramMatchingEnabled: true,
    );

    expect(collect($result['candidates'])->pluck('epg_channel_id'))->toContain($match->id);
});

it('does not widen the candidate pool on Postgres when trigram matching is disabled (default)', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires the pgsql connection (DB_CONNECTION=pgsql) to exercise pg_trgm.');
    }

    DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

    $match = trigramEpgChannel([
        'name' => 'Soprtsnet',
        'display_name' => 'Soprtsnet',
        'channel_id' => 'soprtsnet.us',
    ]);
    $channel = trigramChannel('Sportsnet');

    // No trigramMatchingEnabled argument - defaults to false, same as an
    // EpgMap whose settings never set trigram_matching_enabled.
    $result = app(SimilaritySearchService::class)->findEpgChannelCandidates($channel, $this->epg);

    expect(collect($result['candidates'])->pluck('epg_channel_id'))->not->toContain($match->id);
});

it('does not find the typo candidate on non-Postgres drivers (documents current baseline)', function () {
    if (DB::connection()->getDriverName() === 'pgsql') {
        test()->markTestSkipped('This documents the non-pgsql baseline; skipped when running against pgsql.');
    }

    $match = trigramEpgChannel([
        'name' => 'Soprtsnet',
        'display_name' => 'Soprtsnet',
        'channel_id' => 'soprtsnet.us',
    ]);
    $channel = trigramChannel('Sportsnet');

    $result = app(SimilaritySearchService::class)->findEpgChannelCandidates($channel, $this->epg);

    expect(collect($result['candidates'])->pluck('epg_channel_id'))->not->toContain($match->id);
});

it('credits a trigram-only row in a batch prefetch to the channel whose terms found it', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires the pgsql connection (DB_CONNECTION=pgsql) to exercise pg_trgm.');
    }

    DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

    // Only pg_trgm links "Sportsnet" to "Soprtsnet" (no shared substring), so
    // after a batch prefetch the row has to be attributed back to the
    // Sportsnet channel from Postgres' own trigram result.
    $match = trigramEpgChannel([
        'name' => 'Soprtsnet',
        'display_name' => 'Soprtsnet',
        'channel_id' => 'soprtsnet.us',
    ]);
    $sportsnet = trigramChannel('Sportsnet');
    $weather = trigramChannel('Weatherline');

    $matcher = app(SimilaritySearchService::class);
    $prefetched = $matcher->loadEpgCandidates(
        $this->epg,
        [...$matcher->searchTermsFor($sportsnet), ...$matcher->searchTermsFor($weather)],
        trigramMatchingEnabled: true,
    );
    $candidateIds = fn (Channel $channel) => collect($matcher->findEpgChannelCandidates(
        $channel,
        $this->epg,
        prefetchedCandidates: $prefetched,
        trigramMatchingEnabled: true,
    )['candidates'])->pluck('epg_channel_id');

    expect($candidateIds($sportsnet))->toContain($match->id)
        ->and($candidateIds($weather))->not->toContain($match->id)
        ->and($prefetched->first()->getAttributes())->not->toHaveKey('trigram_positions');
});
