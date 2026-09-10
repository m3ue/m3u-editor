<?php

use App\Filament\Navigation\AdminNavigationLayout;
use App\Filament\Navigation\AdminNavigationMenuBuilder;
use App\Filament\Navigation\AdminNavigationSchema;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Navigation\NavigationBuilder;

function defaultPanelSettings(): array
{
    return [
        'push_relay_enabled' => true,
        'device_pairing_enabled' => true,
    ];
}

it('keeps the stored order first, then appends unstored available keys in their canonical order', function () {
    $result = AdminNavigationLayout::mergeOrder(
        ['playlist', 'live_channels', 'vod_channels', 'epg'],
        ['epg', 'playlist'],
    );

    expect($result)->toBe(['epg', 'playlist', 'live_channels', 'vod_channels']);
});

it('drops stored keys that are no longer available', function () {
    $result = AdminNavigationLayout::mergeOrder(
        ['playlist', 'live_channels'],
        ['tools', 'playlist', 'removed_resource'],
    );

    expect($result)->toBe(['playlist', 'live_channels']);
});

it('builds a canonical snapshot matching the schema order with nothing hidden', function () {
    $snapshot = AdminNavigationLayout::canonicalSnapshot(defaultPanelSettings());
    $schema = AdminNavigationSchema::groups(defaultPanelSettings());

    expect($snapshot['groups']['order'])->toBe(array_keys($schema))
        ->and($snapshot['groups']['hidden'])->toBe([])
        ->and($snapshot['items']['playlist']['order'])->toBe(array_keys($schema['playlist']['items']))
        ->and($snapshot['items']['playlist']['hidden'])->toBe([]);
});

it('renders the canonical default order for an admin when nothing is stored', function () {
    $this->actingAs(User::factory()->admin()->create());

    $builder = app(AdminNavigationMenuBuilder::class)->build(new NavigationBuilder, defaultPanelSettings());

    $groupLabels = collect($builder->getNavigation())->map(fn ($group) => $group->getLabel())->filter()->values()->all();

    expect($groupLabels)->toBe([
        'Administration', 'Playlist', 'DVR', 'Integrations', 'Live Channels',
        'VOD Channels', 'Series', 'EPG', 'Proxy', 'Plugins', 'Tools',
    ]);
});

it('hides a group that an admin marked hidden in the stored layout', function () {
    $this->actingAs(User::factory()->admin()->create());

    $settings = app(GeneralSettings::class);
    $settings->admin_nav_layout = [
        'groups' => ['order' => [], 'hidden' => ['tools']],
        'items' => [],
    ];
    $settings->save();

    $builder = app(AdminNavigationMenuBuilder::class)->build(new NavigationBuilder, defaultPanelSettings());

    $groupLabels = collect($builder->getNavigation())->map(fn ($group) => $group->getLabel())->filter()->values()->all();

    expect($groupLabels)->not->toContain('Tools');
});

it('reorders groups to the stored order, moving new groups to the end', function () {
    $this->actingAs(User::factory()->admin()->create());

    $settings = app(GeneralSettings::class);
    $settings->admin_nav_layout = [
        'groups' => ['order' => ['epg', 'playlist'], 'hidden' => []],
        'items' => [],
    ];
    $settings->save();

    $builder = app(AdminNavigationMenuBuilder::class)->build(new NavigationBuilder, defaultPanelSettings());

    $groupLabels = collect($builder->getNavigation())->map(fn ($group) => $group->getLabel())->filter()->values()->all();

    expect($groupLabels[0])->toBe('EPG')
        ->and($groupLabels[1])->toBe('Playlist');
});

it('hides an item within a group per the stored layout', function () {
    $this->actingAs(User::factory()->admin()->create());

    $settings = app(GeneralSettings::class);
    $settings->admin_nav_layout = [
        'groups' => ['order' => [], 'hidden' => []],
        'items' => [
            'live_channels' => ['order' => [], 'hidden' => ['channels']],
        ],
    ];
    $settings->save();

    $builder = app(AdminNavigationMenuBuilder::class)->build(new NavigationBuilder, defaultPanelSettings());

    $liveChannelsGroup = collect($builder->getNavigation())
        ->first(fn ($group) => $group->getLabel() === 'Live Channels');

    $itemLabels = collect($liveChannelsGroup->getItems())->map(fn ($item) => $item->getLabel())->all();

    expect($itemLabels)->not->toContain('Channels');
});

it('never exposes a group the requesting user is not permitted to see, even if stored as visible', function () {
    $settings = app(GeneralSettings::class);
    $settings->admin_nav_layout = [
        'groups' => ['order' => [], 'hidden' => []],
        'items' => [],
    ];
    $settings->save();

    // A non-admin user without DVR/Proxy permissions.
    $this->actingAs(User::factory()->create());

    $builder = app(AdminNavigationMenuBuilder::class)->build(new NavigationBuilder, defaultPanelSettings());

    $groupLabels = collect($builder->getNavigation())->map(fn ($group) => $group->getLabel())->filter()->values()->all();

    expect($groupLabels)->not->toContain('Administration')
        ->not->toContain('DVR')
        ->not->toContain('Proxy');
});
