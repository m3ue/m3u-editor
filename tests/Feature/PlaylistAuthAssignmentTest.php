<?php

use App\Filament\Resources\PlaylistAuths\Pages\ListPlaylistAuths;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Pivots\PlaylistAuthPivot;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create(['name' => 'Shared playlist P']);
    $this->otherPlaylist = Playlist::factory()->for($this->user)->create(['name' => 'Playlist Q']);
    $this->foreignPlaylist = Playlist::factory()->create(['name' => 'Other owner playlist']);
    $this->authA = PlaylistAuth::factory()->for($this->user)->create();
    $this->authA->assignTo($this->playlist);
});

it('offers an occupied owned standard playlist when creating or editing another auth', function (string $operation) {
    $authB = $operation === 'edit' ? PlaylistAuth::factory()->for($this->user)->create() : null;
    $action = $authB ? TestAction::make('edit')->table($authB) : 'create';
    $component = Livewire::test(ListPlaylistAuths::class)->mountAction($action);
    $page = $component->instance();
    $field = $page->{$page->getMountedActionSchemaName()}->getComponent('assigned_playlist', withHidden: false);
    $options = array_column($field->getOptionsForJs(), 'value');
    $target = Playlist::class.'|'.$this->playlist->id;

    expect($options)->toContain(Playlist::class.'|'.$this->otherPlaylist->id)
        ->not->toContain(Playlist::class.'|'.$this->foreignPlaylist->id)
        ->toContain($target);

    $component->fillForm([
        'name' => 'Auth B',
        'username' => 'assignment-auth-b',
        'password' => 'synthetic-password',
        'assigned_playlist' => $target,
    ], $page->getMountedActionSchemaName())
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $authB = PlaylistAuth::where('username', 'assignment-auth-b')->firstOrFail();
    expect($authB->fresh()->getAssignedModel()->is($this->playlist))->toBeTrue()
        ->and($this->authA->fresh()->getAssignedModel()->is($this->playlist))->toBeTrue()
        ->and($this->playlist->fresh()->playlistAuths()->pluck('playlist_auths.id')->all())
        ->toEqualCanonicalizing([$this->authA->id, $authB->id])
        ->and(PlaylistAuthPivot::where('playlist_auth_id', $authB->id)->count())->toBe(1)
        ->and(PlaylistAuthPivot::where('playlist_auth_id', $this->authA->id)->count())->toBe(1)
        ->and($this->otherPlaylist->fresh()->playlistAuths()->count())->toBe(0);
})->with(['create', 'edit']);

it('retains switches and clears only the edited auth assignment', function () {
    $authB = PlaylistAuth::factory()->for($this->user)->create(['password' => 'synthetic-password']);
    $authB->assignTo($this->playlist);
    $neighbour = PlaylistAuth::factory()->for($this->user)->create();
    $neighbour->assignTo($this->otherPlaylist);
    $originalPivots = PlaylistAuthPivot::whereIn('playlist_auth_id', [$this->authA->id, $neighbour->id])->get()->toArray();
    $targetP = Playlist::class.'|'.$this->playlist->id;
    $targetQ = Playlist::class.'|'.$this->otherPlaylist->id;

    $component = Livewire::test(ListPlaylistAuths::class)
        ->mountAction(TestAction::make('edit')->table($authB))
        ->assertActionDataSet(['assigned_playlist' => $targetP]);
    $page = $component->instance();
    $options = $page->{$page->getMountedActionSchemaName()}->getComponent('assigned_playlist')->getOptionsForJs();
    expect(array_column($options, 'value'))->toContain($targetP, $targetQ);

    $component->callMountedAction()->assertHasNoActionErrors();
    expect($authB->fresh()->getAssignedModel()->is($this->playlist))->toBeTrue();

    $component->mountAction(TestAction::make('edit')->table($authB))
        ->fillForm(['assigned_playlist' => $targetQ], $component->instance()->getMountedActionSchemaName())
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($authB->fresh()->getAssignedModel()->is($this->otherPlaylist))->toBeTrue()
        ->and(PlaylistAuthPivot::where('playlist_auth_id', $authB->id)->count())->toBe(1)
        ->and($this->playlist->fresh()->playlistAuths()->pluck('playlist_auths.id')->all())->toBe([$this->authA->id])
        ->and($this->otherPlaylist->fresh()->playlistAuths()->pluck('playlist_auths.id')->all())
        ->toEqualCanonicalizing([$authB->id, $neighbour->id]);

    $component->mountAction(TestAction::make('edit')->table($authB))
        ->fillForm(['assigned_playlist' => null], $component->instance()->getMountedActionSchemaName())
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($authB->fresh()->getAssignedModel())->toBeNull()
        ->and(PlaylistAuthPivot::where('playlist_auth_id', $authB->id)->count())->toBe(0)
        ->and(PlaylistAuthPivot::whereIn('playlist_auth_id', [$this->authA->id, $neighbour->id])->get()->toArray())
        ->toBe($originalPivots);
});

it('supports the same shared assignment through the registered playlist edit form', function () {
    $authB = PlaylistAuth::factory()->for($this->user)->create();
    $this->get(PlaylistResource::getUrl('edit', ['record' => $this->playlist]))->assertOk();

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->assertFormFieldExists('assigned_auth_ids', function (Select $field) use ($authB): bool {
            expect(array_column($field->getOptionsForJs(), 'value'))->toContain((string) $this->authA->id, (string) $authB->id);

            return true;
        })
        ->fillForm(['user_agent' => 'synthetic-agent', 'assigned_auth_ids' => [$this->authA->id, $authB->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($authB->fresh()->getAssignedModel()->is($this->playlist))->toBeTrue()
        ->and($this->authA->fresh()->getAssignedModel()->is($this->playlist))->toBeTrue()
        ->and(PlaylistAuthPivot::where('playlist_auth_id', $authB->id)->count())->toBe(1)
        ->and(PlaylistAuthPivot::where('playlist_auth_id', $this->authA->id)->count())->toBe(1)
        ->and($this->playlist->fresh()->playlistAuths()->count())->toBe(2)
        ->and($this->otherPlaylist->fresh()->playlistAuths()->count())->toBe(0);
});

it('rejects an unoffered other-owner playlist on auth creation', function () {
    Livewire::test(ListPlaylistAuths::class)
        ->callAction('create', data: [
            'name' => 'Foreign target attempt',
            'username' => 'foreign-target-attempt',
            'password' => 'synthetic-password',
            'assigned_playlist' => Playlist::class.'|'.$this->foreignPlaylist->id,
        ])
        ->assertHasActionErrors(['assigned_playlist']);

    $this->assertDatabaseMissing('playlist_auths', ['username' => 'foreign-target-attempt']);
    expect($this->foreignPlaylist->fresh()->playlistAuths()->count())->toBe(0)
        ->and($this->authA->fresh()->getAssignedModel()->is($this->playlist))->toBeTrue();
});
