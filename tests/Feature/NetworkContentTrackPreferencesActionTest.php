<?php

use App\Filament\Resources\Networks\Pages\EditNetwork;
use App\Filament\Resources\Networks\RelationManagers\NetworkContentRelationManager;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create([
        'permissions' => ['use_integrations'],
    ]);

    $this->actingAs($this->user);
});

it('shows the Track Preferences action for an Emby-linked network', function () {
    $integration = MediaServerIntegration::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'emby',
    ]);
    $network = Network::factory()->create([
        'user_id' => $this->user->id,
        'media_server_integration_id' => $integration->id,
    ]);
    $episode = Episode::factory()->create();
    $content = NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Episode::class,
        'contentable_id' => $episode->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    Livewire::test(NetworkContentRelationManager::class, [
        'ownerRecord' => $network,
        'pageClass' => EditNetwork::class,
    ])->assertActionVisible(TestAction::make('trackPreferences')->table($content));
});

it('hides the Track Preferences action for a Local-linked network', function () {
    $integration = MediaServerIntegration::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'local',
    ]);
    $network = Network::factory()->create([
        'user_id' => $this->user->id,
        'media_server_integration_id' => $integration->id,
    ]);
    $episode = Episode::factory()->create();
    $content = NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Episode::class,
        'contentable_id' => $episode->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    Livewire::test(NetworkContentRelationManager::class, [
        'ownerRecord' => $network,
        'pageClass' => EditNetwork::class,
    ])->assertActionHidden(TestAction::make('trackPreferences')->table($content));
});

it('saves the selected per-item audio and subtitle track override', function () {
    $integration = MediaServerIntegration::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'emby',
        'host' => 'emby.local',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'emby-token',
    ]);
    $network = Network::factory()->create([
        'user_id' => $this->user->id,
        'media_server_integration_id' => $integration->id,
    ]);
    $episode = Episode::factory()->create(['source_episode_id' => 555]);
    $content = NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Episode::class,
        'contentable_id' => $episode->id,
        'sort_order' => 1,
        'weight' => 1,
    ]);

    Http::fake([
        'http://emby.local:8096/Items*' => Http::response([
            'Items' => [[
                'Id' => '555',
                'MediaSources' => [['Id' => 'ms-1']],
                'MediaStreams' => [
                    [
                        'Index' => 1,
                        'Type' => 'Audio',
                        'Language' => 'jpn',
                        'DisplayTitle' => 'Japanese AAC 2.0',
                    ],
                    [
                        'Index' => 2,
                        'Type' => 'Subtitle',
                        'Language' => 'eng',
                        'DisplayTitle' => 'English SRT',
                        'Codec' => 'srt',
                        'IsTextSubtitleStream' => true,
                    ],
                ],
            ]],
        ], 200),
    ]);

    Livewire::test(NetworkContentRelationManager::class, [
        'ownerRecord' => $network,
        'pageClass' => EditNetwork::class,
    ])
        // Only audio stream (Index 1) -> type-relative position 0 -> "0:1";
        // only subtitle stream (Index 2) -> position 0 -> "0:2". These composite
        // values are exactly what getAvailableTracks() builds into the Select's
        // options, so they're what the picker actually submits.
        ->callAction(TestAction::make('trackPreferences')->table($content), data: [
            'preferred_audio_track' => '0:1',
            'preferred_subtitle_track' => '0:2',
        ]);

    expect($content->refresh()->preferred_audio_track)->toBe('0:1')
        ->and($content->preferred_subtitle_track)->toBe('0:2');
});
