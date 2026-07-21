<?php

use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('resolves matchable ids to itself for a standard epg', function () {
    $epg = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create(['is_merged' => false]));

    expect($epg->matchableEpgIds())->toBe([$epg->id]);
});

it('resolves matchable ids to source epgs in pivot sort order for a merged epg', function () {
    $sourceA = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create(['is_merged' => false]));
    $sourceB = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create(['is_merged' => false]));
    $merged = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create(['is_merged' => true]));

    // Attach out of id order to prove sort_order (not id) drives priority.
    $merged->sourceEpgs()->attach($sourceB->id, ['sort_order' => 1]);
    $merged->sourceEpgs()->attach($sourceA->id, ['sort_order' => 0]);

    expect($merged->matchableEpgIds())->toBe([$sourceA->id, $sourceB->id]);
});

it('returns channels from all source epgs for a merged epg', function () {
    $sourceA = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create(['is_merged' => false]));
    $sourceB = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create(['is_merged' => false]));
    $merged = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create(['is_merged' => true]));

    $merged->sourceEpgs()->attach($sourceA->id, ['sort_order' => 0]);
    $merged->sourceEpgs()->attach($sourceB->id, ['sort_order' => 1]);

    $onlyInA = EpgChannel::factory()->for($sourceA)->for($this->user)->create(['channel_id' => 'only-in-a']);
    $onlyInB = EpgChannel::factory()->for($sourceB)->for($this->user)->create(['channel_id' => 'only-in-b']);

    $channelIds = $merged->matchableChannels()->pluck('channel_id')->all();

    expect($channelIds)->toContain('only-in-a', 'only-in-b');
});

it('resolves duplicate channel ids to the first (highest-priority) source', function () {
    $primary = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create(['is_merged' => false, 'name' => 'Primary Source']));
    $secondary = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create(['is_merged' => false, 'name' => 'Secondary Source']));
    $merged = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create(['is_merged' => true]));

    $merged->sourceEpgs()->attach($primary->id, ['sort_order' => 0]);
    $merged->sourceEpgs()->attach($secondary->id, ['sort_order' => 1]);

    EpgChannel::factory()->for($primary)->for($this->user)->create([
        'channel_id' => 'dupe.id',
        'name' => 'Primary Channel',
    ]);
    EpgChannel::factory()->for($secondary)->for($this->user)->create([
        'channel_id' => 'dupe.id',
        'name' => 'Secondary Channel',
    ]);

    $match = $merged->matchableChannels()->where('channel_id', 'dupe.id')->first();

    expect($match->name)->toBe('Primary Channel');
});
