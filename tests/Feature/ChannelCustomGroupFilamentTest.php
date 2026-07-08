<?php

use App\Filament\Resources\CustomPlaylists\RelationManagers\ChannelsRelationManager;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Tags\Tag;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->customPlaylist = CustomPlaylist::factory()->create(['user_id' => $this->user->id]);
    $this->actingAs($this->user);
});

it('can display channels grouped by custom tags in filament table', function () {
    // Create channels with different custom groups
    $sportsChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'ESPN',
        'is_vod' => false,
    ]);

    $newsChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'CNN',
        'is_vod' => false,
    ]);

    $uncategorizedChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'Random Channel',
        'is_vod' => false,
    ]);

    // Create tags for different groups
    $sportsTag = Tag::create([
        'name' => ['en' => 'Sports'],
        'type' => $this->customPlaylist->uuid,
    ]);

    $newsTag = Tag::create([
        'name' => ['en' => 'News'],
        'type' => $this->customPlaylist->uuid,
    ]);

    // Attach tags to channels
    $sportsChannel->attachTag($sportsTag);
    $newsChannel->attachTag($newsTag);
    // Leave uncategorizedChannel without tags

    // Attach channels to the custom playlist
    $this->customPlaylist->channels()->attach([$sportsChannel->id, $newsChannel->id, $uncategorizedChannel->id]);

    // Test the relation manager
    $relationManager = Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $this->customPlaylist,
        'pageClass' => 'App\\Filament\\Resources\\CustomPlaylistResource\\Pages\\EditCustomPlaylist',
    ]);

    // Check that the table contains all channels
    $relationManager
        ->assertCanSeeTableRecords([$sportsChannel, $newsChannel, $uncategorizedChannel]);

    // Test that grouping works by verifying the group names are computed correctly
    expect($sportsChannel->getCustomGroupName($this->customPlaylist->uuid))->toBe('Sports');
    expect($newsChannel->getCustomGroupName($this->customPlaylist->uuid))->toBe('News');
    expect($uncategorizedChannel->getCustomGroupName($this->customPlaylist->uuid))->toBe('Uncategorized');
});

it('shows the source epg for selected epg channels in custom playlists', function () {
    $epg = Epg::factory()->for($this->user)->create([
        'name' => 'Jessmann XML',
    ]);

    $epgChannel = EpgChannel::factory()->for($this->user)->for($epg)->create([
        'name' => 'BBC One EPG',
    ]);

    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'BBC One',
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
    ]);

    $this->customPlaylist->channels()->attach($channel->id);

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $this->customPlaylist,
        'pageClass' => 'App\\Filament\\Resources\\CustomPlaylistResource\\Pages\\EditCustomPlaylist',
    ])
        ->loadTable()
        ->assertTableColumnExists('epgChannel.epg.name')
        ->assertSee('Jessmann XML');
});
