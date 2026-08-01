<?php

use App\Filament\Resources\MediaServerIntegrations\Pages\ListMediaServerIntegrations;
use App\Models\Channel;
use App\Models\MediaServerIntegration;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);
});

it('shows added vs active counts for movies and series instead of the infinity symbol for aiostreams integrations', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);
    $playlist = $integration->getOrCreatePlaylist();

    Channel::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'is_custom' => true,
        'enabled' => true,
    ]);
    Channel::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'is_custom' => true,
        'enabled' => false,
    ]);

    Series::factory()->count(4)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'is_custom' => true,
        'enabled' => true,
    ]);
    Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'is_custom' => true,
        'enabled' => false,
    ]);

    Livewire::test(ListMediaServerIntegrations::class)
        ->assertDontSee('∞')
        ->assertSee('5')
        ->assertSee('Active: 3')
        ->assertSee('Active: 4');
});
