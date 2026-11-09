<?php

use App\Filament\Clusters\Settings\Pages\ManageNavigationSettings;
use App\Models\User;
use App\Settings\GeneralSettings;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('fills the groups repeater with the canonical default order when nothing is stored', function () {
    Livewire::test(ManageNavigationSettings::class)
        ->assertOk()
        ->assertSet('data.admin_nav_active_preset', 'default');
});

it('saves a hidden group and reordered items as a custom layout', function () {
    Livewire::test(ManageNavigationSettings::class)
        ->fillForm(function (array $data) {
            // Hide the "Tools" group and flip the order of the first two items in "Playlist".
            foreach ($data['groups'] as $i => $group) {
                if ($group['key'] === 'tools') {
                    $data['groups'][$i]['visible'] = false;
                }

                if ($group['key'] === 'playlist') {
                    $items = $group['items'];
                    $keys = array_keys($items);
                    [$items[$keys[0]], $items[$keys[1]]] = [$items[$keys[1]], $items[$keys[0]]];
                    $data['groups'][$i]['items'] = $items;
                }
            }

            return $data;
        })
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(GeneralSettings::class)->refresh();

    expect($settings->admin_nav_active_preset)->toBe('custom')
        ->and($settings->admin_nav_layout['groups']['hidden'])->toContain('tools')
        ->and($settings->admin_nav_layout['items']['playlist']['order'][0])->toBe('custom_playlists');
});

it('hides a group\'s items in the editor once the group toggle is turned off', function () {
    $component = Livewire::test(ManageNavigationSettings::class);

    $groups = $component->get('data.groups');
    $toolsKey = collect($groups)->search(fn (array $group) => $group['key'] === 'tools');
    $firstToolsItemLabel = collect($groups[$toolsKey]['items'])->first()['label'];

    $component->assertSee($firstToolsItemLabel);

    $component->set("data.groups.{$toolsKey}.visible", false);

    $component->assertDontSee($firstToolsItemLabel);
});

it('restores the default layout, clearing any stored customization', function () {
    $settings = app(GeneralSettings::class);
    $settings->admin_nav_layout = ['groups' => ['order' => [], 'hidden' => ['tools']], 'items' => []];
    $settings->admin_nav_active_preset = 'custom';
    $settings->save();

    Livewire::test(ManageNavigationSettings::class)
        ->callAction('restore_default')
        ->assertNotified();

    $settings = app(GeneralSettings::class)->refresh();

    expect($settings->admin_nav_layout)->toBeNull()
        ->and($settings->admin_nav_active_preset)->toBe('default');
});

it('preserves a hidden Cached Downloads preference across a save made while caching is disabled', function () {
    $settings = app(GeneralSettings::class);
    $settings->enable_cache = true;
    $settings->save();

    // Hide Cached Downloads while caching is enabled, so it's offered in the form.
    Livewire::test(ManageNavigationSettings::class)
        ->fillForm(function (array $data) {
            foreach ($data['groups'] as $i => $group) {
                if ($group['key'] === 'playlist') {
                    foreach ($group['items'] as $j => $item) {
                        if ($item['key'] === 'cached_downloads') {
                            $data['groups'][$i]['items'][$j]['visible'] = false;
                        }
                    }
                }
            }

            return $data;
        })
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GeneralSettings::class)->refresh()->admin_nav_layout['items']['playlist']['hidden'])
        ->toContain('cached_downloads');

    // Disable caching, so Cached Downloads is no longer offered, then save an
    // unrelated change (hiding the Tools group).
    $settings = app(GeneralSettings::class);
    $settings->enable_cache = false;
    $settings->save();

    Livewire::test(ManageNavigationSettings::class)
        ->fillForm(function (array $data) {
            foreach ($data['groups'] as $i => $group) {
                if ($group['key'] === 'tools') {
                    $data['groups'][$i]['visible'] = false;
                }
            }

            return $data;
        })
        ->call('save')
        ->assertHasNoFormErrors();

    // Re-enable caching and confirm the hidden preference for Cached Downloads survived.
    $settings = app(GeneralSettings::class);
    $settings->enable_cache = true;
    $settings->save();

    expect(app(GeneralSettings::class)->refresh()->admin_nav_layout['items']['playlist']['hidden'])
        ->toContain('cached_downloads')
        ->and(app(GeneralSettings::class)->admin_nav_layout['groups']['hidden'])
        ->toContain('tools');
});

it('applies the shipped simplified default layout via the Use Simplified Default action', function () {
    Livewire::test(ManageNavigationSettings::class)
        ->callAction('restore_simplified_default')
        ->assertNotified();

    $settings = app(GeneralSettings::class)->refresh();

    expect($settings->admin_nav_active_preset)->toBe('simplified')
        ->and($settings->admin_nav_layout['groups']['hidden'])->toContain('dvr', 'plugins');
});
