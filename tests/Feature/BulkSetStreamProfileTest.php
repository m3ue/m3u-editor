<?php

use App\Filament\Actions\BulkModalActionGroup;
use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\Vods\VodResource;
use App\Jobs\ProcessM3uImportChunk;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\StreamProfile;
use App\Models\User;
use Filament\Actions\BulkAction;
use Filament\Schemas\Components\Component;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    $this->profileA = StreamProfile::factory()->for($this->user)->create(['name' => 'Profile A']);
    $this->profileB = StreamProfile::factory()->for($this->user)->create(['name' => 'Profile B']);
});

/**
 * Flatten the names of every BulkAction registered inside a BulkModalActionGroup
 * schema, regardless of whether they live under Fieldset sections or a flat Grid.
 */
function flattenBulkActionNames(array $bulkActions): array
{
    $group = $bulkActions[0];
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

// ── Action registration ──────────────────────────────────────────────────────

it('registers set-stream-profile inside the channel BulkModalActionGroup', function () {
    expect(flattenBulkActionNames(ChannelResource::getTableBulkActions()))
        ->toContain('set-stream-profile');
});

it('registers set-stream-profile inside the VOD BulkModalActionGroup', function () {
    expect(flattenBulkActionNames(VodResource::getTableBulkActions()))
        ->toContain('set-stream-profile');
});

// ── Channel bulk action: overwrite semantics ─────────────────────────────────

/**
 * Mirrors the SQL the bulk action executes. Returning the affected row count
 * lets us assert on it the same way the action does for its success notification.
 */
function applyChannelStreamProfile($records, ?int $profileId, bool $overwrite): int
{
    $updated = 0;
    foreach ($records->chunk(100) as $chunk) {
        $query = Channel::whereIn('id', $chunk->pluck('id'));
        if (! $overwrite) {
            $query->whereNull('stream_profile_id');
        }
        $updated += $query->update(['stream_profile_id' => $profileId]);
    }

    return $updated;
}

it('assigns the profile to channels without one when overwrite is off', function () {
    $channels = Channel::factory()->count(3)->for($this->user)->for($this->playlist)
        ->create(['stream_profile_id' => null]);

    $updated = applyChannelStreamProfile($channels, $this->profileA->id, overwrite: false);

    expect($updated)->toBe(3);
    foreach ($channels as $channel) {
        expect($channel->fresh()->stream_profile_id)->toBe($this->profileA->id);
    }
});

it('skips channels that already have a profile when overwrite is off', function () {
    $assigned = Channel::factory()->for($this->user)->for($this->playlist)
        ->create(['stream_profile_id' => $this->profileA->id]);
    $blank = Channel::factory()->for($this->user)->for($this->playlist)
        ->create(['stream_profile_id' => null]);

    $updated = applyChannelStreamProfile(
        Channel::whereIn('id', [$assigned->id, $blank->id])->get(),
        $this->profileB->id,
        overwrite: false,
    );

    expect($updated)->toBe(1);
    expect($assigned->fresh()->stream_profile_id)->toBe($this->profileA->id);
    expect($blank->fresh()->stream_profile_id)->toBe($this->profileB->id);
});

it('overwrites every selected channel when overwrite is on', function () {
    $assigned = Channel::factory()->for($this->user)->for($this->playlist)
        ->create(['stream_profile_id' => $this->profileA->id]);
    $blank = Channel::factory()->for($this->user)->for($this->playlist)
        ->create(['stream_profile_id' => null]);

    $updated = applyChannelStreamProfile(
        Channel::whereIn('id', [$assigned->id, $blank->id])->get(),
        $this->profileB->id,
        overwrite: true,
    );

    expect($updated)->toBe(2);
    expect($assigned->fresh()->stream_profile_id)->toBe($this->profileB->id);
    expect($blank->fresh()->stream_profile_id)->toBe($this->profileB->id);
});

it('clears profiles when null is passed with overwrite on', function () {
    $channels = Channel::factory()->count(2)->for($this->user)->for($this->playlist)
        ->create(['stream_profile_id' => $this->profileA->id]);

    $updated = applyChannelStreamProfile($channels, null, overwrite: true);

    expect($updated)->toBe(2);
    foreach ($channels as $channel) {
        expect($channel->fresh()->stream_profile_id)->toBeNull();
    }
});

it('does not clear existing profiles when null is passed with overwrite off', function () {
    $channels = Channel::factory()->count(2)->for($this->user)->for($this->playlist)
        ->create(['stream_profile_id' => $this->profileA->id]);

    $updated = applyChannelStreamProfile($channels, null, overwrite: false);

    expect($updated)->toBe(0);
    foreach ($channels as $channel) {
        expect($channel->fresh()->stream_profile_id)->toBe($this->profileA->id);
    }
});

// ── Group bulk action: only live, persistence toggle ─────────────────────────

it('per-row action: updates only live channels in the single group', function () {
    $group = Group::factory()->for($this->user)->for($this->playlist)
        ->create(['type' => 'live', 'stream_profile_id' => null]);
    $live = Channel::factory()->count(2)->for($this->user)->for($this->playlist)
        ->create(['group_id' => $group->id, 'is_vod' => false, 'stream_profile_id' => null]);
    $vod = Channel::factory()->for($this->user)->for($this->playlist)
        ->create(['group_id' => $group->id, 'is_vod' => true, 'stream_profile_id' => null]);

    $group->live_channels()->whereNull('stream_profile_id')
        ->update(['stream_profile_id' => $this->profileA->id]);

    foreach ($live as $channel) {
        expect($channel->fresh()->stream_profile_id)->toBe($this->profileA->id);
    }
    expect($vod->fresh()->stream_profile_id)->toBeNull();
});

it('per-row action: persists group default when apply_to_new_channels is on', function () {
    $group = Group::factory()->for($this->user)->for($this->playlist)
        ->create(['type' => 'live', 'stream_profile_id' => null]);

    $group->update(['stream_profile_id' => $this->profileA->id]);

    expect($group->fresh()->stream_profile_id)->toBe($this->profileA->id);
});

it('updates only live channels when applying via a live group', function () {
    $liveGroup = Group::factory()->for($this->user)->for($this->playlist)
        ->create(['type' => 'live']);

    $live = Channel::factory()->count(2)->for($this->user)->for($this->playlist)
        ->create(['group_id' => $liveGroup->id, 'is_vod' => false, 'stream_profile_id' => null]);
    $vod = Channel::factory()->for($this->user)->for($this->playlist)
        ->create(['group_id' => $liveGroup->id, 'is_vod' => true, 'stream_profile_id' => null]);

    $liveGroup->live_channels()->whereNull('stream_profile_id')
        ->update(['stream_profile_id' => $this->profileA->id]);

    foreach ($live as $channel) {
        expect($channel->fresh()->stream_profile_id)->toBe($this->profileA->id);
    }
    expect($vod->fresh()->stream_profile_id)->toBeNull();
});

it('persists stream_profile_id on the group when apply_to_new_channels is on', function () {
    $group = Group::factory()->for($this->user)->for($this->playlist)
        ->create(['type' => 'live', 'stream_profile_id' => null]);

    $group->update(['stream_profile_id' => $this->profileA->id]);

    expect($group->fresh()->stream_profile_id)->toBe($this->profileA->id);
});

it('leaves the group default unchanged when apply_to_new_channels is off', function () {
    $group = Group::factory()->for($this->user)->for($this->playlist)
        ->create(['type' => 'live', 'stream_profile_id' => $this->profileA->id]);

    Channel::factory()->for($this->user)->for($this->playlist)
        ->create(['group_id' => $group->id, 'is_vod' => false, 'stream_profile_id' => null]);

    $group->live_channels()->whereNull('stream_profile_id')
        ->update(['stream_profile_id' => $this->profileB->id]);

    expect($group->fresh()->stream_profile_id)->toBe($this->profileA->id);
});

// ── Observer: inherit group default on Channel::create() ─────────────────────

it('inherits the group stream_profile_id when creating a channel without one', function () {
    $group = Group::factory()->for($this->user)->for($this->playlist)
        ->create(['type' => 'live', 'stream_profile_id' => $this->profileA->id]);

    $channel = Channel::create([
        'name' => 'Inheriting Channel',
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $group->id,
        'is_vod' => false,
    ]);

    expect($channel->stream_profile_id)->toBe($this->profileA->id);
});

it('keeps an explicit channel stream_profile_id over the group default', function () {
    $group = Group::factory()->for($this->user)->for($this->playlist)
        ->create(['type' => 'live', 'stream_profile_id' => $this->profileA->id]);

    $channel = Channel::create([
        'name' => 'Explicit Channel',
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $group->id,
        'is_vod' => false,
        'stream_profile_id' => $this->profileB->id,
    ]);

    expect($channel->stream_profile_id)->toBe($this->profileB->id);
});

it('leaves stream_profile_id null when the group has no default', function () {
    $group = Group::factory()->for($this->user)->for($this->playlist)
        ->create(['type' => 'live', 'stream_profile_id' => null]);

    $channel = Channel::create([
        'name' => 'No Inheritance',
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $group->id,
        'is_vod' => false,
    ]);

    expect($channel->stream_profile_id)->toBeNull();
});

it('leaves stream_profile_id null when the channel has no group', function () {
    $channel = Channel::create([
        'name' => 'Orphan Channel',
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => null,
        'is_vod' => false,
    ]);

    expect($channel->stream_profile_id)->toBeNull();
});

// ── Import chunk: inject group default; preserve existing on re-import ───────

beforeEach(function () {
    $this->tempJobsDb = sys_get_temp_dir().'/jobs_test_'.uniqid().'.sqlite';
    touch($this->tempJobsDb);
    config(['database.connections.jobs.database' => $this->tempJobsDb]);
    DB::purge('jobs');
    $migration = require database_path('migrations/2025_02_13_215803_create_jobs_table.php');
    $migration->up();
});

afterEach(function () {
    DB::purge('jobs');
    config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);
    if (isset($this->tempJobsDb) && file_exists($this->tempJobsDb)) {
        @unlink($this->tempJobsDb);
    }
});

it('injects the group stream_profile_id into newly imported channels', function () {
    $group = Group::factory()->for($this->user)->for($this->playlist)
        ->create(['type' => 'live', 'stream_profile_id' => $this->profileA->id]);

    $job = Job::create([
        'title' => 'Import test',
        'batch_no' => 'test-batch-1',
        'variables' => [
            'playlistId' => $this->playlist->id,
            'groupId' => $group->id,
            'groupName' => $group->name,
        ],
        'payload' => [
            [
                'name' => 'New Live Channel',
                'title' => 'New Live Channel',
                'url' => 'http://example.test/stream/1',
                'source_id' => 'new-channel-1',
                'user_id' => $this->user->id,
                'playlist_id' => $this->playlist->id,
                'is_vod' => false,
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ],
    ]);

    (new ProcessM3uImportChunk([$job->id], 1))->handle();

    $channel = Channel::where('source_id', 'new-channel-1')->first();
    expect($channel)->not->toBeNull();
    expect($channel->stream_profile_id)->toBe($this->profileA->id);
});

it('does not overwrite an existing channel stream_profile_id on re-import', function () {
    $group = Group::factory()->for($this->user)->for($this->playlist)
        ->create(['type' => 'live', 'stream_profile_id' => $this->profileA->id]);

    $existing = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'group_id' => $group->id,
        'source_id' => 'existing-channel-1',
        'is_vod' => false,
        'stream_profile_id' => $this->profileB->id,
    ]);

    $job = Job::create([
        'title' => 'Re-import test',
        'batch_no' => 'test-batch-2',
        'variables' => [
            'playlistId' => $this->playlist->id,
            'groupId' => $group->id,
            'groupName' => $group->name,
        ],
        'payload' => [
            [
                'name' => $existing->name,
                'title' => $existing->title ?? $existing->name,
                'url' => 'http://example.test/stream/updated',
                'source_id' => 'existing-channel-1',
                'user_id' => $this->user->id,
                'playlist_id' => $this->playlist->id,
                'is_vod' => false,
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ],
    ]);

    (new ProcessM3uImportChunk([$job->id], 1))->handle();

    expect($existing->fresh()->stream_profile_id)->toBe($this->profileB->id);
});
