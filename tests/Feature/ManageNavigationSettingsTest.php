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

it('applies the shipped simplified default layout via the Use Simplified Default action', function () {
    Livewire::test(ManageNavigationSettings::class)
        ->callAction('restore_simplified_default')
        ->assertNotified();

    $settings = app(GeneralSettings::class)->refresh();

    expect($settings->admin_nav_active_preset)->toBe('simplified')
        ->and($settings->admin_nav_layout['groups']['hidden'])->toContain('dvr', 'plugins');
});
