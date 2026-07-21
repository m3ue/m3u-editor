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

    // Single-word name so the only search term is the whole 9-letter word;
    // a two-letter transposition breaks any literal LIKE substring match,
    // but pg_trgm similarity() still sees the two strings as close.
    $match = trigramEpgChannel([
        'name' => 'Soprtsnet',
        'display_name' => 'Soprtsnet',
        'channel_id' => 'soprtsnet.us',
    ]);
    $channel = trigramChannel('Sportsnet');

    $result = app(SimilaritySearchService::class)->findEpgChannelCandidates($channel, $this->epg);

    expect(collect($result['candidates'])->pluck('epg_channel_id'))->toContain($match->id);
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
