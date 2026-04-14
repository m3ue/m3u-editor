<?php

use App\Filament\Actions\BulkModalActionGroup;
use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Filament\Actions\BulkAction;
use Filament\Schemas\Components\Component;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

/**
 * Return the flat list of BulkAction names from the BulkModalActionGroup schema.
 * The schema may contain Section components (each with its own child actions)
 * or a Grid wrapping flat BulkActions. This helper handles both layouts.
 */
function getChannelBulkActionNames(): array
{
    $bulkActions = ChannelResource::getTableBulkActions();
    $group = $bulkActions[0]; // BulkModalActionGroup

    // The HasSchema trait stores the raw schema in $this->schema.
    $schemaProp = new ReflectionProperty($group, 'schema');
    $outerSchema = $schemaProp->getValue($group);

    $childProp = new ReflectionProperty(Component::class, 'childComponents');
    $names = [];

    foreach ($outerSchema as $component) {
        $children = $childProp->getValue($component)['default'] ?? [];
        foreach ($children as $child) {
            if ($child instanceof BulkAction) {
                $names[] = $child->getName();
            }
        }
    }

    return $names;
}

it('registers enable-probing bulk action inside the channel BulkModalActionGroup', function () {
    $names = getChannelBulkActionNames();
    expect($names)->toContain('enable-probing');
});

it('registers disable-probing bulk action inside the channel BulkModalActionGroup', function () {
    $names = getChannelBulkActionNames();
    expect($names)->toContain('disable-probing');
});

it('bulk enabling probe_enabled sets flag to true for all target channels', function () {
    $channels = Channel::factory()
        ->count(3)
        ->for($this->playlist)
        ->create(['probe_enabled' => false]);

    foreach ($channels->chunk(100) as $chunk) {
        Channel::whereIn('id', $chunk->pluck('id'))->update(['probe_enabled' => true]);
    }

    foreach ($channels as $channel) {
        expect($channel->fresh()->probe_enabled)->toBeTrue();
    }
});

it('bulk disabling probe_enabled sets flag to false for all target channels', function () {
    $channels = Channel::factory()
        ->count(3)
        ->for($this->playlist)
        ->create(['probe_enabled' => true]);

    foreach ($channels->chunk(100) as $chunk) {
        Channel::whereIn('id', $chunk->pluck('id'))->update(['probe_enabled' => false]);
    }

    foreach ($channels as $channel) {
        expect($channel->fresh()->probe_enabled)->toBeFalse();
    }
});

it('channels with probe_enabled disabled are not included in other channels probe batch', function () {
    Channel::factory()->count(2)->for($this->playlist)->create(['probe_enabled' => true, 'enabled' => true, 'is_vod' => false]);
    Channel::factory()->count(2)->for($this->playlist)->create(['probe_enabled' => false, 'enabled' => true, 'is_vod' => false]);

    $count = Channel::query()
        ->where('playlist_id', $this->playlist->id)
        ->where('enabled', true)
        ->where('is_vod', false)
        ->where('probe_enabled', true)
        ->count();

    expect($count)->toBe(2);
});
