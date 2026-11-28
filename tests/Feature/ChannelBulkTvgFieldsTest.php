<?php

/**
 * Bulk "Set TVG Type" and "Set TVG ID" channel actions: set (or clear, when left empty) the
 * user overrides `tvg_type` and `stream_id_custom` on the selected channels only, leaving the
 * provider values (`stream_type`, `stream_id`) alone.
 */

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Filament\Actions\BulkAction;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

/**
 * Find a channel bulk action by name inside the sectioned BulkModalActionGroup schema.
 */
function channelBulkTvgFieldsAction(string $name): BulkAction
{
    $group = ChannelResource::getTableBulkActions()[0];
    $sections = (new ReflectionProperty($group, 'schema'))->getValue($group);
    $childProp = new ReflectionProperty(Component::class, 'childComponents');

    foreach ($sections as $section) {
        foreach ($childProp->getValue($section)['default'] ?? [] as $child) {
            if ($child instanceof BulkAction && $child->getName() === $name) {
                return $child;
            }
        }
    }

    throw new RuntimeException("Bulk action [{$name}] not found.");
}

/**
 * Run a bulk action's handler against the given channels with the submitted form data.
 *
 * @param  array<string, mixed>  $data
 */
function runChannelBulkTvgFieldsAction(string $name, array $channels, array $data): void
{
    $action = channelBulkTvgFieldsAction($name);
    $action->evaluate($action->getActionFunction(), [
        'records' => new EloquentCollection($channels),
        'recordsQuery' => Channel::query()->whereKey(collect($channels)->pluck('id')),
        'data' => $data,
    ]);
}

it('sets the TVG Type override on the selected channels only', function () {
    [$first, $second, $untouched] = Channel::factory()->count(3)->for($this->user)->for($this->playlist)
        ->create(['tvg_type' => null, 'stream_type' => 'live'])->all();

    runChannelBulkTvgFieldsAction('set-tvg-type', [$first, $second], ['tvg_type' => ' radio_streams ']);

    expect($first->fresh()->tvg_type)->toBe('radio_streams')
        ->and($second->fresh()->tvg_type)->toBe('radio_streams')
        ->and($first->fresh()->stream_type)->toBe('live')
        ->and($untouched->fresh()->tvg_type)->toBeNull();
});

it('clears the TVG Type override when left empty', function (?string $submitted) {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)
        ->create(['tvg_type' => 'radio_streams', 'stream_type' => 'live']);

    runChannelBulkTvgFieldsAction('set-tvg-type', [$channel], ['tvg_type' => $submitted]);

    expect($channel->fresh()->tvg_type)->toBeNull()
        ->and($channel->fresh()->resolveXtreamLiveStreamType())->toBe('live');
})->with(['null' => [null], 'blank' => ['   ']]);

it('sets the TVG ID override on the selected channels only', function () {
    [$first, $second, $untouched] = Channel::factory()->count(3)->for($this->user)->for($this->playlist)
        ->create(['stream_id_custom' => null, 'stream_id' => 'provider.id'])->all();

    runChannelBulkTvgFieldsAction('set-tvg-id', [$first, $second], ['stream_id_custom' => 'shared.epg.id']);

    expect($first->fresh()->stream_id_custom)->toBe('shared.epg.id')
        ->and($second->fresh()->stream_id_custom)->toBe('shared.epg.id')
        ->and($first->fresh()->stream_id)->toBe('provider.id')
        ->and($untouched->fresh()->stream_id_custom)->toBeNull();
});

it('clears the TVG ID override when left empty', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)
        ->create(['stream_id_custom' => 'custom.id', 'stream_id' => 'provider.id']);

    runChannelBulkTvgFieldsAction('set-tvg-id', [$channel], ['stream_id_custom' => '']);

    expect($channel->fresh()->stream_id_custom)->toBeNull();
});
