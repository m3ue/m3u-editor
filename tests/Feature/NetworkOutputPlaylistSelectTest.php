<?php

use App\Filament\Resources\Networks\Pages\CreateNetwork;
use App\Filament\Resources\Networks\Pages\EditNetwork;
use App\Models\Network;
use App\Models\Playlist;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;

beforeEach(function () {
    $this->user = User::factory()->create([
        'permissions' => ['use_integrations'],
    ]);

    $this->actingAs($this->user);
});

it('preloads eligible network output playlists on the create form', function () {
    $eligiblePlaylist = Playlist::factory()->create([
        'name' => 'Network Output',
        'user_id' => $this->user->id,
        'is_network_playlist' => true,
    ]);

    $regularPlaylist = Playlist::factory()->create([
        'name' => 'Regular Playlist',
        'user_id' => $this->user->id,
        'is_network_playlist' => false,
    ]);

    $otherUserPlaylist = Playlist::factory()->create([
        'name' => 'Other User Output',
        'is_network_playlist' => true,
    ]);

    Livewire::test(CreateNetwork::class)
        ->assertFormFieldExists('network_playlist_id', function (Select $field) use ($eligiblePlaylist, $regularPlaylist, $otherUserPlaylist): bool {
            $encodedOptions = json_encode($field->getOptionsForJs(), JSON_THROW_ON_ERROR);

            Assert::assertStringContainsString('"value":"' . $eligiblePlaylist->getKey() . '"', $encodedOptions);
            Assert::assertStringContainsString($eligiblePlaylist->name, $encodedOptions);
            Assert::assertStringNotContainsString($regularPlaylist->name, $encodedOptions);
            Assert::assertStringNotContainsString($otherUserPlaylist->name, $encodedOptions);

            return true;
        });
});

it('creates and selects a network output playlist from the create form', function () {
    Livewire::test(CreateNetwork::class)
        ->callAction(TestAction::make('createOption')->schemaComponent('network_playlist_id'), data: [
            'name' => 'New Network Output',
        ])
        ->assertHasNoFormErrors()
        ->assertSchemaStateSet(function (array $state): void {
            $playlist = Playlist::query()->where('name', 'New Network Output')->first();

            Assert::assertNotNull($playlist);
            Assert::assertSame($this->user->id, $playlist->user_id);
            Assert::assertTrue($playlist->is_network_playlist);
            Assert::assertEquals($playlist->getKey(), $state['network_playlist_id']);
        });
});

it('scopes network output playlists to the current user on the edit form', function () {
    $network = Network::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $eligiblePlaylist = Playlist::factory()->create([
        'name' => 'Network Output',
        'user_id' => $this->user->id,
        'is_network_playlist' => true,
    ]);

    $regularPlaylist = Playlist::factory()->create([
        'name' => 'Regular Playlist',
        'user_id' => $this->user->id,
        'is_network_playlist' => false,
    ]);

    $otherUserPlaylist = Playlist::factory()->create([
        'name' => 'Other User Output',
        'is_network_playlist' => true,
    ]);

    Livewire::test(EditNetwork::class, ['record' => $network->getKey()])
        ->assertFormFieldExists('network_playlist_id', function (Select $field) use ($eligiblePlaylist, $regularPlaylist, $otherUserPlaylist): bool {
            $encodedOptions = json_encode($field->getOptionsForJs(), JSON_THROW_ON_ERROR);

            Assert::assertStringContainsString('"value":"' . $eligiblePlaylist->getKey() . '"', $encodedOptions);
            Assert::assertStringContainsString($eligiblePlaylist->name, $encodedOptions);
            Assert::assertStringNotContainsString($regularPlaylist->name, $encodedOptions);
            Assert::assertStringNotContainsString($otherUserPlaylist->name, $encodedOptions);

            return true;
        });
});
