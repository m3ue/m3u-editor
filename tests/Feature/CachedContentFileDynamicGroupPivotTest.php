<?php

use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory() fires PlaylistCreated → SyncPipelineService → Redis lock. No Redis
    // locally, so Bus::fake() is required to intercept the dispatch from the model listener.
    Bus::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    // DynamicGroup fillable: playlist_id, user_id, type, source, name, tmdb_params, sort_order,
    // enabled, last_synced_at. Minimum for create: playlist_id, user_id, type, source, name.
    $this->group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Test Group',
    ]);

    $this->file = CachedContentFile::factory()->create();
});

it('attaches a cached file to a dynamic group', function () {
    $this->file->dynamicGroups()->attach($this->group->id);

    expect($this->file->dynamicGroups()->count())->toBe(1)
        ->and($this->group->cachedContentFiles()->count())->toBe(1);
});

it('detaches a cached file from a dynamic group', function () {
    $this->file->dynamicGroups()->attach($this->group->id);
    $this->file->dynamicGroups()->detach($this->group->id);

    expect($this->file->dynamicGroups()->count())->toBe(0)
        ->and($this->group->cachedContentFiles()->count())->toBe(0);
});

it('cascades pivot row deletion when the file is deleted', function () {
    $this->file->dynamicGroups()->attach($this->group->id);
    $this->file->delete();

    expect($this->group->cachedContentFiles()->count())->toBe(0);
});

it('cascades pivot row deletion when the group is deleted', function () {
    $this->file->dynamicGroups()->attach($this->group->id);
    $this->group->delete();

    expect($this->file->dynamicGroups()->count())->toBe(0);
});

it('treats syncWithoutDetaching as idempotent — adding the same id twice keeps one row', function () {
    // attach() is not idempotent — it tries to INSERT and fails on the unique
    // constraint. syncWithoutDetaching() is the idempotent variant for "ensure
    // these are linked" semantics.
    $this->file->dynamicGroups()->syncWithoutDetaching([$this->group->id]);
    $this->file->dynamicGroups()->syncWithoutDetaching([$this->group->id]);

    expect($this->file->dynamicGroups()->count())->toBe(1);
});

it('attach() rejects duplicate file-group links with a UNIQUE constraint violation', function () {
    // Documenting the behavior so future callers know to use syncWithoutDetaching.
    $this->file->dynamicGroups()->attach($this->group->id);

    expect(fn () => $this->file->dynamicGroups()->attach($this->group->id))
        ->toThrow(QueryException::class);
});
