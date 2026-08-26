<?php

use App\Filament\Resources\DvrRecordingRules\Pages\CreateDvrRecordingRule;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
use Filament\Forms\Components\Select;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;

beforeEach(function () {
    $this->user = User::factory()->create(['permissions' => ['use_dvr']]);
    $this->actingAs($this->user);
});

it('labels a custom playlist DVR setting with the playlist name, not a numeric fallback', function () {
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create(['name' => 'merged_m3u']);
    $dvrSetting = DvrSetting::factory()->enabled()->create([
        'user_id' => $this->user->id,
        'playlist_id' => null,
        'custom_playlist_id' => $customPlaylist->id,
    ]);

    Livewire::test(CreateDvrRecordingRule::class)
        ->assertFormFieldExists('dvr_setting_id', function (Select $field) use ($dvrSetting, $customPlaylist): bool {
            Assert::assertSame($customPlaylist->name, $field->getOptions()[$dvrSetting->id] ?? null);

            return true;
        });
});

it('only lists DVR settings that have DVR enabled', function () {
    $enabledPlaylist = Playlist::factory()->for($this->user)->create();
    $enabledSetting = DvrSetting::factory()->enabled()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $enabledPlaylist->id,
    ]);

    $disabledPlaylist = Playlist::factory()->for($this->user)->create();
    $disabledSetting = DvrSetting::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $disabledPlaylist->id,
        'enabled' => false,
    ]);

    Livewire::test(CreateDvrRecordingRule::class)
        ->assertFormFieldExists('dvr_setting_id', function (Select $field) use ($enabledSetting, $disabledSetting): bool {
            $options = $field->getOptions();

            Assert::assertArrayHasKey($enabledSetting->id, $options);
            Assert::assertArrayNotHasKey($disabledSetting->id, $options);

            return true;
        });
});

it('scopes the channel selector to the currently selected playlist', function () {
    $playlistOne = CustomPlaylist::factory()->for($this->user)->create();
    $dvrSettingOne = DvrSetting::factory()->enabled()->create([
        'user_id' => $this->user->id,
        'playlist_id' => null,
        'custom_playlist_id' => $playlistOne->id,
    ]);
    $channelOne = Channel::factory()->create(['user_id' => $this->user->id, 'title' => 'Channel One']);
    $playlistOne->channels()->attach($channelOne->id);

    $playlistTwo = CustomPlaylist::factory()->for($this->user)->create();
    DvrSetting::factory()->enabled()->create([
        'user_id' => $this->user->id,
        'playlist_id' => null,
        'custom_playlist_id' => $playlistTwo->id,
    ]);
    $channelTwo = Channel::factory()->create(['user_id' => $this->user->id, 'title' => 'Channel Two']);
    $playlistTwo->channels()->attach($channelTwo->id);

    Livewire::test(CreateDvrRecordingRule::class)
        ->fillForm(['dvr_setting_id' => $dvrSettingOne->id])
        ->assertFormFieldExists('channel_id', function (Select $field) use ($channelOne, $channelTwo): bool {
            $options = $field->getOptions();

            Assert::assertArrayHasKey($channelOne->id, $options);
            Assert::assertArrayNotHasKey($channelTwo->id, $options);

            return true;
        });
});
