<?php

use App\Filament\Resources\Plugins\Pages\ManagePluginTable;
use App\Filament\Resources\Plugins\PluginResource;
use App\Models\Playlist;
use App\Models\Plugin;
use App\Models\User;
use App\Plugins\PluginSchemaManager;
use App\Plugins\PluginSchemaMapper;
use App\Plugins\PluginUiTableRegistry;
use App\Plugins\PluginValidator;
use App\Plugins\Support\PluginManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Exists;
use Livewire\Livewire;

function declaredTableUiPlugin(): Plugin
{
    $tableName = 'plugin_declared_table_ui_profiles';
    $schema = [
        'tables' => [[
            'name' => $tableName,
            'columns' => [
                ['type' => 'id', 'name' => 'id'],
                ['type' => 'foreignId', 'name' => 'extension_plugin_id', 'references' => 'extension_plugins', 'on_delete' => 'cascade'],
                ['type' => 'string', 'name' => 'name'],
                ['type' => 'boolean', 'name' => 'enabled', 'default' => true],
                ['type' => 'json', 'name' => 'settings', 'nullable' => true],
                ['type' => 'timestamps'],
            ],
            'indexes' => [],
        ]],
        'ui_tables' => [[
            'id' => 'profiles',
            'label' => 'Profiles',
            'model_label' => 'Profile',
            'table' => $tableName,
            'description' => 'Reusable test profiles.',
            'columns' => [
                ['name' => 'name', 'label' => 'Name', 'searchable' => true, 'sortable' => true],
                ['name' => 'enabled', 'label' => 'Enabled', 'type' => 'boolean'],
                ['name' => 'settings.source_mode', 'label' => 'Source Mode', 'options' => ['native_playlist' => 'Native Playlist']],
            ],
            'fields' => [
                ['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['id' => 'enabled', 'label' => 'Enabled', 'type' => 'boolean', 'default' => true],
                ['id' => 'settings.source_mode', 'label' => 'Source Mode', 'type' => 'select', 'options' => ['native_playlist' => 'Native Playlist']],
            ],
        ]],
    ];

    $plugin = Plugin::query()->create([
        'plugin_id' => 'declared-table-ui-'.Str::lower(Str::random(6)),
        'name' => 'Declared Table UI',
        'version' => '1.0.0',
        'api_version' => config('plugins.api_version'),
        'description' => 'Declarative table UI fixture.',
        'entrypoint' => 'Plugin.php',
        'class_name' => 'AppLocalPlugins\\DeclaredTableUi\\Plugin',
        'capabilities' => [],
        'hooks' => [],
        'permissions' => ['schema_manage'],
        'schema_definition' => $schema,
        'actions' => [],
        'settings_schema' => [],
        'settings' => [],
        'data_ownership' => [
            'tables' => [$tableName],
            'directories' => [],
            'files' => [],
            'default_cleanup_policy' => 'preserve',
        ],
        'source_type' => 'local_directory',
        'path' => storage_path('app/testing-plugin-sources/declared-table-ui'),
        'available' => true,
        'enabled' => true,
        'installation_status' => 'installed',
        'trust_state' => 'trusted',
        'validation_status' => 'valid',
        'integrity_status' => 'verified',
    ]);

    app(PluginSchemaManager::class)->apply($schema);

    DB::table($tableName)->insert([
        'extension_plugin_id' => $plugin->id,
        'name' => 'Default Profile',
        'enabled' => true,
        'settings' => json_encode(['source_mode' => 'native_playlist'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $plugin;
}

function declaredPrefilledTableUiPlugin(): array
{
    $suffix = Str::lower(Str::random(6));
    $profilesTable = "plugin_declared_table_ui_profiles_{$suffix}";
    $linksTable = "plugin_declared_table_ui_links_{$suffix}";
    $schema = [
        'tables' => [
            [
                'name' => $profilesTable,
                'columns' => [
                    ['type' => 'id', 'name' => 'id'],
                    ['type' => 'foreignId', 'name' => 'extension_plugin_id', 'references' => 'extension_plugins', 'on_delete' => 'cascade'],
                    ['type' => 'string', 'name' => 'name'],
                    ['type' => 'boolean', 'name' => 'enabled', 'default' => true],
                    ['type' => 'timestamps'],
                ],
                'indexes' => [],
            ],
            [
                'name' => $linksTable,
                'columns' => [
                    ['type' => 'id', 'name' => 'id'],
                    ['type' => 'foreignId', 'name' => 'extension_plugin_id', 'references' => 'extension_plugins', 'on_delete' => 'cascade'],
                    ['type' => 'foreignId', 'name' => 'playlist_id', 'references' => 'playlists', 'on_delete' => 'cascade'],
                    ['type' => 'foreignId', 'name' => 'extension_plugin_profile_id', 'references' => $profilesTable, 'nullable' => true, 'on_delete' => 'null'],
                    ['type' => 'foreignId', 'name' => 'user_id', 'references' => 'users', 'nullable' => true, 'on_delete' => 'null'],
                    ['type' => 'boolean', 'name' => 'enabled', 'default' => false],
                    ['type' => 'json', 'name' => 'settings', 'nullable' => true],
                    ['type' => 'timestamps'],
                ],
                'indexes' => [
                    ['type' => 'unique', 'columns' => ['extension_plugin_id', 'playlist_id'], 'name' => "plugin_table_ui_links_unique_{$suffix}"],
                ],
            ],
        ],
        'ui_tables' => [[
            'id' => 'playlist_assignments',
            'label' => 'Playlist Assignments',
            'model_label' => 'Playlist Assignment',
            'table' => $linksTable,
            'create' => false,
            'delete' => false,
            'prefill' => [
                'source' => [
                    'table' => 'playlists',
                    'key_column' => 'id',
                    'user_column' => 'user_id',
                    'order_column' => 'name',
                    'scope' => 'owned',
                ],
                'target_column' => 'playlist_id',
                'defaults' => [
                    'enabled' => false,
                    'settings.run_availability' => true,
                    'settings.run_sync' => true,
                ],
            ],
            'columns' => [
                ['name' => 'playlist_id', 'label' => 'Playlist', 'lookup' => ['table' => 'playlists', 'label_column' => 'name']],
                ['name' => 'extension_plugin_profile_id', 'label' => 'Profile', 'type' => 'select', 'editable' => true, 'lookup' => ['table' => $profilesTable, 'label_column' => 'name', 'scope_plugin' => true]],
                ['name' => 'enabled', 'label' => 'Enabled', 'type' => 'boolean', 'editable' => true],
                ['name' => 'settings.run_availability', 'label' => 'Availability', 'type' => 'boolean', 'editable' => true],
                ['name' => 'settings.run_sync', 'label' => 'Sync', 'type' => 'boolean', 'editable' => true],
            ],
            'fields' => [],
        ]],
    ];

    $plugin = Plugin::query()->create([
        'plugin_id' => 'prefilled-table-ui-'.$suffix,
        'name' => 'Prefilled Table UI',
        'version' => '1.0.0',
        'api_version' => config('plugins.api_version'),
        'description' => 'Prefilled table UI fixture.',
        'entrypoint' => 'Plugin.php',
        'class_name' => 'AppLocalPlugins\\PrefilledTableUi\\Plugin',
        'capabilities' => [],
        'hooks' => [],
        'permissions' => ['schema_manage'],
        'schema_definition' => $schema,
        'actions' => [],
        'settings_schema' => [],
        'settings' => [],
        'data_ownership' => [
            'tables' => [$profilesTable, $linksTable],
            'directories' => [],
            'files' => [],
            'default_cleanup_policy' => 'preserve',
        ],
        'source_type' => 'local_directory',
        'path' => storage_path('app/testing-plugin-sources/prefilled-table-ui'),
        'available' => true,
        'enabled' => true,
        'installation_status' => 'installed',
        'trust_state' => 'trusted',
        'validation_status' => 'valid',
        'integrity_status' => 'verified',
    ]);

    app(PluginSchemaManager::class)->apply($schema);

    DB::table($profilesTable)->insert([
        'extension_plugin_id' => $plugin->id,
        'name' => 'Default Profile',
        'enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$plugin, $profilesTable, $linksTable];
}

it('renders plugin-declared table UIs through the generic plugin table page', function () {
    $this->actingAs(User::factory()->admin()->create());

    $plugin = declaredTableUiPlugin();

    expect(PluginResource::getUrl('table', ['record' => $plugin, 'table' => 'profiles']))
        ->toContain('/tables/profiles');

    Livewire::test(ManagePluginTable::class, ['record' => $plugin->getRouteKey(), 'table' => 'profiles'])
        ->assertOk()
        ->assertSee('Default Profile')
        ->assertSee('Native Playlist');
});

it('prefills plugin-declared table rows from an owned source table', function () {
    $user = User::factory()->admin()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($user);

    [$alpha, $beta] = Playlist::withoutEvents(fn (): array => [
        Playlist::factory()->for($user)->create(['name' => 'Alpha Playlist']),
        Playlist::factory()->for($user)->create(['name' => 'Beta Playlist']),
    ]);

    Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for($otherUser)->create(['name' => 'Other User Playlist']));

    [$plugin, , $linksTable] = declaredPrefilledTableUiPlugin();

    expect(Playlist::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and(Schema::hasColumn($linksTable, 'playlist_id'))->toBeTrue();

    $component = Livewire::test(ManagePluginTable::class, ['record' => $plugin->getRouteKey(), 'table' => 'playlist_assignments'])
        ->assertOk()
        ->assertSee('Alpha Playlist')
        ->assertSee('Beta Playlist')
        ->assertSee('None')
        ->assertDontSee('Other User Playlist');

    expect($component->get('tableDefinition.prefill'))->not->toBeNull();

    $rows = DB::table($linksTable)->orderBy('playlist_id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('playlist_id')->all())->toBe([$alpha->id, $beta->id])
        ->and($rows->every(fn (object $row): bool => $row->extension_plugin_profile_id === null))->toBeTrue()
        ->and($rows->every(fn (object $row): bool => ! (bool) $row->enabled))->toBeTrue()
        ->and(json_decode((string) $rows->first()->settings, true))->toMatchArray([
            'run_availability' => true,
            'run_sync' => true,
        ]);
});

it('throws when ui_tables is not a list in the manifest', function () {
    expect(fn () => PluginManifest::fromArray([
        'id' => 'test-plugin',
        'name' => 'Test Plugin',
        'permissions' => [],
        'schema' => ['ui_tables' => 'not-an-array'],
    ], '/tmp/test-plugin'))
        ->toThrow(RuntimeException::class, 'Manifest field [schema.ui_tables] must be a list.');
});

it('returns validation errors (not TypeError) when ui_table columns or fields is a non-array', function () {
    $suffix = Str::lower(Str::random(6));
    $tableName = "plugin_test_{$suffix}_items";

    $manifest = PluginManifest::fromArray([
        'id' => "test-{$suffix}",
        'name' => 'Test Plugin',
        'permissions' => [],
        'schema' => [
            'tables' => [['name' => $tableName, 'columns' => [['type' => 'id', 'name' => 'id']]]],
            'ui_tables' => [[
                'id' => 'items',
                'table' => $tableName,
                'label' => 'Items',
                'columns' => 'bad-string',
                'fields' => 'bad-string',
            ]],
        ],
    ], '/tmp/test-plugin');

    $validator = app(PluginValidator::class);
    $method = new ReflectionMethod($validator, 'validateSchema');
    $errors = $method->invoke($validator, $manifest);

    expect($errors)->toContain('schema.ui_tables.0.columns must be a list.')
        ->and($errors)->toContain('schema.ui_tables.0.fields must be a list.');
});

it('generates an exists rule for table_select settings fields', function () {
    $suffix = Str::lower(Str::random(6));
    $tableName = "plugin_table_select_test_{$suffix}";

    $plugin = Plugin::query()->create([
        'plugin_id' => "table-select-rules-{$suffix}",
        'name' => 'Table Select Rules',
        'version' => '1.0.0',
        'api_version' => config('plugins.api_version'),
        'description' => 'Test fixture for table_select validation rules.',
        'entrypoint' => 'Plugin.php',
        'class_name' => 'AppLocalPlugins\\TableSelectRules\\Plugin',
        'capabilities' => [],
        'hooks' => [],
        'permissions' => [],
        'schema_definition' => [
            'tables' => [['name' => $tableName, 'columns' => [['type' => 'id', 'name' => 'id']]]],
            'ui_tables' => [],
        ],
        'actions' => [],
        'settings_schema' => [[
            'id' => 'selected_item',
            'label' => 'Selected Item',
            'type' => 'table_select',
            'table' => $tableName,
        ]],
        'settings' => [],
        'data_ownership' => [
            'tables' => [$tableName],
            'directories' => [],
            'files' => [],
            'default_cleanup_policy' => 'preserve',
        ],
        'source_type' => 'local_directory',
        'path' => storage_path('app/testing-plugin-sources/table-select-rules'),
        'available' => true,
        'enabled' => true,
        'installation_status' => 'installed',
        'trust_state' => 'trusted',
        'validation_status' => 'valid',
        'integrity_status' => 'verified',
    ]);

    $rules = app(PluginSchemaMapper::class)->settingsRules($plugin);

    expect($rules)->toHaveKey('settings.selected_item')
        ->and(collect($rules['settings.selected_item'])->contains(fn (mixed $r): bool => $r instanceof Exists))->toBeTrue();
});

it('caps prefill inserts at the prefill_max_source_rows config limit', function () {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    [$plugin, , $linksTable] = declaredPrefilledTableUiPlugin();

    $cap = 2;
    config(['plugins.prefill_max_source_rows' => $cap]);

    Playlist::withoutEvents(fn (): array => [
        Playlist::factory()->for($user)->create(['name' => 'Playlist One']),
        Playlist::factory()->for($user)->create(['name' => 'Playlist Two']),
        Playlist::factory()->for($user)->create(['name' => 'Playlist Three']),
    ]);

    $tableDefinition = data_get($plugin->schema_definition, 'ui_tables.0');
    app(PluginUiTableRegistry::class)->prefillRows($plugin, $tableDefinition);

    expect(DB::table($linksTable)->count())->toBe($cap);
});
