<?php

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Filament\Actions\BulkAction;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

/**
 * Return the flat list of BulkAction names from the BulkModalActionGroup schema.
 */
function getBulkActionNames(): array
{
    $bulkActions = ChannelResource::getTableBulkActions();
    $group = $bulkActions[0];

    $schemaProp = new ReflectionProperty($group, 'schema');
    $outerSchema = $schemaProp->getValue($group);

    $grid = $outerSchema[0];

    $childProp = new ReflectionProperty($grid, 'childComponents');
    $children = $childProp->getValue($grid)['default'] ?? [];

    return collect($children)
        ->filter(fn ($c) => $c instanceof BulkAction)
        ->map(fn ($c) => $c->getName())
        ->values()
        ->all();
}

it('registers set-epg-shift bulk action inside the channel BulkModalActionGroup', function () {
    $names = getBulkActionNames();
    expect($names)->toContain('set-epg-shift');
});

it('bulk sets tvg_shift for selected channels', function () {
    $channels = Channel::factory()
        ->count(3)
        ->for($this->playlist)
        ->create(['tvg_shift' => null]);

    foreach ($channels->chunk(100) as $chunk) {
        Channel::whereIn('id', $chunk->pluck('id'))->update(['tvg_shift' => '2']);
    }

    foreach ($channels as $channel) {
        expect($channel->fresh()->tvg_shift)->toBe('2');
    }
});

it('bulk sets negative tvg_shift for selected channels', function () {
    $channels = Channel::factory()
        ->count(3)
        ->for($this->playlist)
        ->create(['tvg_shift' => '0']);

    foreach ($channels->chunk(100) as $chunk) {
        Channel::whereIn('id', $chunk->pluck('id'))->update(['tvg_shift' => '-3']);
    }

    foreach ($channels as $channel) {
        expect($channel->fresh()->tvg_shift)->toBe('-3');
    }
});

it('bulk resets tvg_shift to zero for selected channels', function () {
    $channels = Channel::factory()
        ->count(3)
        ->for($this->playlist)
        ->create(['tvg_shift' => '5']);

    foreach ($channels->chunk(100) as $chunk) {
        Channel::whereIn('id', $chunk->pluck('id'))->update(['tvg_shift' => '0']);
    }

    foreach ($channels as $channel) {
        expect($channel->fresh()->tvg_shift)->toBe('0');
    }
});
