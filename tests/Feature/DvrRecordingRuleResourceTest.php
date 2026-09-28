<?php

use App\Enums\DvrSeriesMode;
use App\Filament\Resources\DvrRecordingRules\Pages\CreateDvrRecordingRule;
use App\Filament\Resources\DvrRecordingRules\Pages\EditDvrRecordingRule;
use App\Filament\Resources\DvrRecordingRules\Pages\ListDvrRecordingRules;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\Playlist;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\View;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;

beforeEach(function () {
    $this->user = User::factory()->create(['permissions' => ['use_dvr']]);
    $this->actingAs($this->user);
});

it('re-renders the airings preview from the edited form values on an existing rule', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $dvrSetting = DvrSetting::factory()->enabled()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
    ]);

    $epg = Epg::factory()->for($this->user)->create();
    $epgChannel = EpgChannel::factory()->create([
        'epg_id' => $epg->id,
        'user_id' => $this->user->id,
        'channel_id' => 'test.channel',
    ]);
    $channel = Channel::factory()
        ->for($playlist)
        ->create([
            'epg_channel_id' => $epgChannel->id,
            'title' => 'Test Channel',
            'enabled' => true,
        ]);

    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($dvrSetting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'series_title' => 'Old Show',
            'channel_id' => $channel->id,
            'series_mode' => DvrSeriesMode::All,
        ]);

    EpgProgramme::factory()->upcoming(60)->create([
        'epg_id' => $epg->id,
        'title' => 'Old Show',
        'epg_channel_id' => 'test.channel',
        'subtitle' => 'OLD-SERIES-EPISODE',
    ]);
    EpgProgramme::factory()->upcoming(90)->create([
        'epg_id' => $epg->id,
        'title' => 'New Show',
        'epg_channel_id' => 'test.channel',
        'subtitle' => 'NEW-SERIES-EPISODE',
    ]);

    // Mounting shows the SAVED rule's airings; editing the title (onBlur)
    // must re-render the preview from the form's current values without
    // persisting anything. The preview's view DATA is asserted directly from
    // the page's form schema against the current form state - deterministic
    // in the test harness, unlike full HTML re-render snapshots.
    $assertPreview = function (Testable $page, array $expectedSubtitles, array $absentSubtitles): void {
        $flatten = function ($components) use (&$flatten): array {
            $out = [];
            foreach ($components as $component) {
                $out[] = $component;
                if (method_exists($component, 'getChildComponents')) {
                    $out = array_merge($out, $flatten($component->getChildComponents()));
                }
            }

            return $out;
        };

        $viewField = collect($flatten($page->instance()->form->getComponents()))
            ->first(fn ($component) => $component instanceof View);
        Assert::assertNotNull($viewField, 'Airings preview View component not found');

        // The View field's own viewData is now just the (cheap) props for the
        // deferred preview - resolve it the same way the real wire:init call
        // would (HasDvrMatchedAiringsPreviewCache, mixed into the page).
        $viewData = $viewField->getViewData();
        $page->instance()->loadDvrMatchedAiringsPreview(
            $viewData['cacheKey'],
            $viewData['ruleId'],
            $viewData['ruleAttributes'],
        );

        $subtitles = array_column($page->instance()->dvrAiringsPreview, 'subtitle');

        foreach ($expectedSubtitles as $subtitle) {
            Assert::assertContains($subtitle, $subtitles);
        }
        foreach ($absentSubtitles as $subtitle) {
            Assert::assertNotContains($subtitle, $subtitles);
        }
    };

    $page = Livewire::test(EditDvrRecordingRule::class, ['record' => $rule->getRouteKey()])
        ->fillForm(['series_title' => 'Old Show']);

    $assertPreview($page, ['OLD-SERIES-EPISODE'], ['NEW-SERIES-EPISODE']);

    $page->fillForm(['series_title' => 'New Show']);
    $assertPreview($page, ['NEW-SERIES-EPISODE'], ['OLD-SERIES-EPISODE']);

    expect(DvrRecordingRule::find($rule->id)->series_title)->toBe('Old Show');
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
            $results = $field->getSearchResults('Channel');

            Assert::assertArrayHasKey($channelOne->id, $results);
            Assert::assertArrayNotHasKey($channelTwo->id, $results);

            return true;
        });
});

it('can reopen the edit slideover after closing it once', function () {
    // Regression test: an earlier implementation of the airings preview used a
    // nested #[Lazy] Livewire child component, which corrupted the hosting
    // component's child tracking - the slideover would throw "Snapshot missing"
    // and silently fail to reopen after being closed once. The preview must
    // live entirely on the hosting component (HasDvrMatchedAiringsPreviewCache),
    // not as a separate child.
    $dvrSetting = DvrSetting::factory()->enabled()->create(['user_id' => $this->user->id]);
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($dvrSetting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Old Show']);

    $page = Livewire::test(ListDvrRecordingRules::class)
        ->mountTableAction('edit', $rule)
        ->assertOk()
        ->unmountTableAction()
        ->assertOk()
        ->mountTableAction('edit', $rule)
        ->assertOk();
});

it('scopes a pinned channel to every channel sharing its label, not just the selected row', function () {
    // Regression test: IPTV providers commonly list duplicate rows for the same
    // channel (quality/stream variants). Pinning a rule to one such duplicate
    // must scope by the shared displayed label, not the single selected row -
    // otherwise picking the duplicate with no (or a stale) EPG mapping silently
    // hides airings a sibling duplicate would have matched.
    $playlist = Playlist::factory()->for($this->user)->create();
    $dvrSetting = DvrSetting::factory()->enabled()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
    ]);

    $epg = Epg::factory()->for($this->user)->create();
    $mappedEpgChannel = EpgChannel::factory()->create([
        'epg_id' => $epg->id,
        'user_id' => $this->user->id,
        'channel_id' => 'ms.now',
    ]);

    // The duplicate the user actually pins: disabled, with no EPG mapping.
    $unmappedDuplicate = Channel::factory()->for($playlist)->create([
        'title' => 'MS NOW',
        'epg_channel_id' => null,
        'enabled' => false,
    ]);

    // A sibling duplicate with the correct EPG mapping.
    Channel::factory()->for($playlist)->create([
        'title' => 'MS NOW',
        'epg_channel_id' => $mappedEpgChannel->id,
        'enabled' => true,
    ]);

    EpgProgramme::factory()->upcoming(60)->create([
        'epg_id' => $epg->id,
        'title' => 'MS Now Live',
        'epg_channel_id' => 'ms.now',
        'subtitle' => 'MS-NOW-EPISODE',
    ]);

    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($dvrSetting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'series_title' => 'MS Now Live',
            'channel_id' => $unmappedDuplicate->id,
            'series_mode' => DvrSeriesMode::All,
        ]);

    $page = Livewire::test(EditDvrRecordingRule::class, ['record' => $rule->getRouteKey()])
        ->fillForm(['series_title' => 'MS Now Live']);

    $flatten = function ($components) use (&$flatten): array {
        $out = [];
        foreach ($components as $component) {
            $out[] = $component;
            if (method_exists($component, 'getChildComponents')) {
                $out = array_merge($out, $flatten($component->getChildComponents()));
            }
        }

        return $out;
    };

    $viewField = collect($flatten($page->instance()->form->getComponents()))
        ->first(fn ($component) => $component instanceof View);
    $viewData = $viewField->getViewData();

    $page->instance()->loadDvrMatchedAiringsPreview(
        $viewData['cacheKey'],
        $viewData['ruleId'],
        $viewData['ruleAttributes'],
    );

    $subtitles = array_column($page->instance()->dvrAiringsPreview, 'subtitle');

    expect($subtitles)->toContain('MS-NOW-EPISODE');
});
