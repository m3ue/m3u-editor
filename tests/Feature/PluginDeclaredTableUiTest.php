<?php

use App\Filament\Resources\Plugins\Pages\ManagePluginTable;
use App\Filament\Resources\Plugins\Pages\ViewPluginRun;
use App\Filament\Resources\Plugins\PluginResource;
use App\Livewire\PluginTableInline;
use App\Models\Playlist;
use App\Models\Plugin;
use App\Models\PluginRun;
use App\Models\User;
use App\Plugins\PluginIntegrityService;
use App\Plugins\PluginSchemaManager;
use App\Plugins\PluginSchemaMapper;
use App\Plugins\PluginUiTableRegistry;
use App\Plugins\PluginValidator;
use App\Plugins\Support\PluginManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

function declaredDynamicOptionsTableUiPlugin(): array
{
    $suffix = Str::lower(Str::random(6));
    $pluginId = "dynamic-table-ui-{$suffix}";
    $classSegment = Str::studly(str_replace('-', ' ', $pluginId));
    $tablePrefix = "plugin_dynamic_table_ui_{$suffix}_";
    $tableName = "{$tablePrefix}profiles";
    $sourcePath = storage_path("app/testing-plugin-sources/{$pluginId}");

    $schema = [
        'tables' => [[
            'name' => $tableName,
            'columns' => [
                ['type' => 'id', 'name' => 'id'],
                ['type' => 'foreignId', 'name' => 'extension_plugin_id', 'references' => 'extension_plugins', 'on_delete' => 'cascade'],
                ['type' => 'string', 'name' => 'name'],
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
            'columns' => [
                ['name' => 'name', 'label' => 'Name'],
                [
                    'name' => 'settings.provider_source',
                    'label' => 'Provider Source',
                    'options_provider' => 'fixture_sources',
                    'depends_on' => ['settings.provider', 'settings.country'],
                ],
            ],
            'fields' => [],
        ]],
    ];

    $manifest = [
        'id' => $pluginId,
        'name' => 'Dynamic Table UI',
        'version' => '1.0.0',
        'api_version' => config('plugins.api_version'),
        'description' => 'Declarative table UI dynamic options fixture.',
        'entrypoint' => 'Plugin.php',
        'class' => "AppLocalPlugins\\{$classSegment}\\Plugin",
        'capabilities' => [],
        'hooks' => [],
        'permissions' => ['schema_manage'],
        'settings' => [],
        'actions' => [],
        'schema' => $schema,
        'data_ownership' => [
            'plugin_id' => $pluginId,
            'table_prefix' => $tablePrefix,
            'tables' => [$tableName],
            'directories' => [],
            'files' => [],
            'default_cleanup_policy' => 'preserve',
        ],
    ];

    $pluginSource = <<<PHP
<?php

namespace AppLocalPlugins\\{$classSegment};

use App\\Plugins\\Contracts\\PluginInterface;
use App\\Plugins\\Contracts\\PluginSelectOptionsProviderInterface;
use App\\Plugins\\Support\\PluginActionResult;
use App\\Plugins\\Support\\PluginExecutionContext;
use App\\Plugins\\Support\\PluginSelectOptionsContext;

class Plugin implements PluginInterface, PluginSelectOptionsProviderInterface
{
    public function runAction(string \$action, array \$payload, PluginExecutionContext \$context): PluginActionResult
    {
        return PluginActionResult::success('Fixture plugin action completed.');
    }

    public function selectOptions(string \$provider, PluginSelectOptionsContext \$context): array
    {
        if (\$provider !== 'fixture_sources') {
            return [];
        }

        return [
            'viewersvault' => 'ViewersVault '.\$context->value('settings.provider', 'unknown').'/'.\$context->value('settings.country', 'unknown'),
        ];
    }
}
PHP;

    File::deleteDirectory($sourcePath);
    File::ensureDirectoryExists($sourcePath);
    File::put(
        "{$sourcePath}/plugin.json",
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );
    File::put("{$sourcePath}/Plugin.php", $pluginSource);

    $hashes = app(PluginIntegrityService::class)->hashesForPlugin($sourcePath, 'Plugin.php');

    $plugin = Plugin::query()->create([
        'plugin_id' => $pluginId,
        'name' => 'Dynamic Table UI',
        'version' => '1.0.0',
        'api_version' => config('plugins.api_version'),
        'description' => 'Declarative table UI dynamic options fixture.',
        'entrypoint' => 'Plugin.php',
        'class_name' => "AppLocalPlugins\\{$classSegment}\\Plugin",
        'capabilities' => [],
        'hooks' => [],
        'permissions' => ['schema_manage'],
        'schema_definition' => $schema,
        'actions' => [],
        'settings_schema' => [],
        'settings' => [],
        'data_ownership' => $manifest['data_ownership'],
        'source_type' => 'local_directory',
        'path' => $sourcePath,
        'available' => true,
        'enabled' => true,
        'installation_status' => 'installed',
        'trust_state' => 'trusted',
        'integrity_status' => 'verified',
        'trusted_hashes' => $hashes,
        'manifest_hash' => $hashes['manifest_hash'] ?? null,
        'entrypoint_hash' => $hashes['entrypoint_hash'] ?? null,
        'plugin_hash' => $hashes['plugin_hash'] ?? null,
    ]);

    app(PluginSchemaManager::class)->apply($schema);

    DB::table($tableName)->insert([
        'extension_plugin_id' => $plugin->id,
        'name' => 'Dynamic Profile',
        'settings' => json_encode([
            'provider' => 'sky',
            'country' => 'uk',
            'provider_source' => 'viewersvault',
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$plugin, $sourcePath];
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

function declaredRunResultTableUiPlugin(): array
{
    $suffix = Str::lower(Str::random(6));
    $tableName = "plugin_declared_run_results_{$suffix}";
    $schema = [
        'tables' => [[
            'name' => $tableName,
            'columns' => [
                ['type' => 'id', 'name' => 'id'],
                ['type' => 'foreignId', 'name' => 'extension_plugin_id', 'references' => 'extension_plugins', 'on_delete' => 'cascade'],
                ['type' => 'foreignId', 'name' => 'extension_plugin_run_id', 'references' => 'extension_plugin_runs', 'on_delete' => 'cascade'],
                ['type' => 'foreignId', 'name' => 'playlist_id', 'references' => 'playlists', 'on_delete' => 'cascade'],
                ['type' => 'string', 'name' => 'result_type'],
                ['type' => 'string', 'name' => 'decision'],
                ['type' => 'string', 'name' => 'title'],
                ['type' => 'integer', 'name' => 'score', 'nullable' => true],
                ['type' => 'json', 'name' => 'details', 'nullable' => true],
                ['type' => 'timestamps'],
            ],
            'indexes' => [],
        ]],
        'ui_tables' => [[
            'id' => 'run_results',
            'label' => 'Run Results',
            'model_label' => 'Run Result',
            'table' => $tableName,
            'description' => 'Rows captured by plugin runs.',
            'create' => false,
            'edit' => false,
            'delete' => false,
            'columns' => [
                ['name' => 'created_at', 'label' => 'Created', 'type' => 'datetime', 'sortable' => true],
                ['name' => 'result_type', 'label' => 'Type', 'sortable' => true],
                ['name' => 'decision', 'label' => 'Decision', 'sortable' => true],
                ['name' => 'title', 'label' => 'Title', 'searchable' => true],
                ['name' => 'score', 'label' => 'Score', 'sortable' => true],
                ['name' => 'details', 'label' => 'Details'],
            ],
            'fields' => [],
        ]],
    ];

    $plugin = Plugin::query()->create([
        'plugin_id' => 'run-result-table-ui-'.$suffix,
        'name' => 'Run Result Table UI',
        'version' => '1.0.0',
        'api_version' => config('plugins.api_version'),
        'description' => 'Run result table UI fixture.',
        'entrypoint' => 'Plugin.php',
        'class_name' => 'AppLocalPlugins\\RunResultTableUi\\Plugin',
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
        'path' => storage_path('app/testing-plugin-sources/run-result-table-ui-'.$suffix),
        'available' => true,
        'enabled' => true,
        'installation_status' => 'installed',
        'trust_state' => 'trusted',
        'validation_status' => 'valid',
        'integrity_status' => 'verified',
    ]);

    app(PluginSchemaManager::class)->apply($schema);

    return [$plugin, $tableName];
}

function seedDeclaredRunResultRows(User $user): array
{
    [$plugin, $tableName] = declaredRunResultTableUiPlugin();

    [$playlist, $otherPlaylist] = Playlist::withoutEvents(fn (): array => [
        Playlist::factory()->for($user)->create(['name' => 'Primary Playlist']),
        Playlist::factory()->for($user)->create(['name' => 'Other Playlist']),
    ]);

    $run = PluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'user_id' => $user->id,
        'status' => 'completed',
        'invocation_type' => 'action',
        'action' => 'sync_playlist',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => ['playlist_id' => $playlist->id],
        'result' => ['data' => ['totals' => ['rows' => 1]]],
        'summary' => 'Results ready.',
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $otherRun = PluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'user_id' => $user->id,
        'status' => 'completed',
        'invocation_type' => 'action',
        'action' => 'sync_playlist',
        'trigger' => 'manual',
        'dry_run' => true,
        'payload' => ['playlist_id' => $playlist->id],
        'summary' => 'Other run.',
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $timestamp = now();
    DB::table($tableName)->insert([
        [
            'extension_plugin_id' => $plugin->id,
            'extension_plugin_run_id' => $run->id,
            'playlist_id' => $playlist->id,
            'result_type' => 'sync_excluded',
            'decision' => 'excluded',
            'title' => 'Included Result',
            'score' => 97,
            'details' => json_encode(['reason' => 'matched'], JSON_THROW_ON_ERROR),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ],
        [
            'extension_plugin_id' => $plugin->id,
            'extension_plugin_run_id' => $otherRun->id,
            'playlist_id' => $playlist->id,
            'result_type' => 'sync_excluded',
            'decision' => 'excluded',
            'title' => 'Other Run Result',
            'score' => 91,
            'details' => json_encode(['reason' => 'other_run'], JSON_THROW_ON_ERROR),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ],
        [
            'extension_plugin_id' => $plugin->id,
            'extension_plugin_run_id' => $run->id,
            'playlist_id' => $otherPlaylist->id,
            'result_type' => 'sync_excluded',
            'decision' => 'excluded',
            'title' => 'Other Playlist Result',
            'score' => 88,
            'details' => json_encode(['reason' => 'other_playlist'], JSON_THROW_ON_ERROR),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ],
    ]);

    return [
        'plugin' => $plugin,
        'tableName' => $tableName,
        'playlist' => $playlist,
        'otherPlaylist' => $otherPlaylist,
        'run' => $run,
        'otherRun' => $otherRun,
    ];
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

it('renders plugin-declared table column options from a dynamic provider', function () {
    $this->actingAs(User::factory()->admin()->create());

    [$plugin, $sourcePath] = declaredDynamicOptionsTableUiPlugin();

    try {
        Livewire::test(ManagePluginTable::class, ['record' => $plugin->getRouteKey(), 'table' => 'profiles'])
            ->assertOk()
            ->assertSee('Dynamic Profile')
            ->assertSee('ViewersVault sky/uk');
    } finally {
        File::deleteDirectory($sourcePath);
    }
});

it('applies declared cascade actions to plugin table foreign keys', function () {
    $plugin = declaredTableUiPlugin();
    $tableName = 'plugin_declared_table_ui_profiles';

    expect(DB::table($tableName)->count())->toBe(1);

    $plugin->delete();

    expect(DB::table($tableName)->count())->toBe(0);
});

it('applies declared null actions to plugin table foreign keys', function () {
    $user = User::factory()->create();
    $playlist = Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for($user)->create());

    [$plugin, $profilesTable, $linksTable] = declaredPrefilledTableUiPlugin();

    $profileId = DB::table($profilesTable)->value('id');

    DB::table($linksTable)->insert([
        'extension_plugin_id' => $plugin->id,
        'playlist_id' => $playlist->id,
        'extension_plugin_profile_id' => $profileId,
        'enabled' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table($profilesTable)->where('id', $profileId)->delete();

    expect(DB::table($linksTable)->value('extension_plugin_profile_id'))->toBeNull();
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

it('renders inline plugin table headings from manifest labels', function () {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    $plugin = declaredTableUiPlugin();

    Livewire::test(PluginTableInline::class, ['record' => $plugin, 'tableId' => 'profiles'])
        ->assertOk()
        ->assertSee('Profiles')
        ->assertSee('Reusable test profiles.');
});

it('can clear a plugin table record instead of deleting it', function () {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    $playlist = Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for($user)->create(['name' => 'Assigned Playlist']));
    [$plugin, $profilesTable, $linksTable] = declaredPrefilledTableUiPlugin();
    $profileId = DB::table($profilesTable)->value('id');

    $definition = data_get($plugin->schema_definition, 'ui_tables.0');
    $definition['delete_behavior'] = 'clear';
    $definition['delete_payload'] = [
        'extension_plugin_profile_id' => null,
        'enabled' => false,
        'settings.run_availability' => true,
        'settings.run_sync' => true,
    ];

    app(PluginUiTableRegistry::class)->prefillRows($plugin, $definition);

    $row = DB::table($linksTable)->where('playlist_id', $playlist->id)->first();

    DB::table($linksTable)->where('id', $row->id)->update([
        'extension_plugin_profile_id' => $profileId,
        'enabled' => true,
        'settings' => json_encode([
            'run_availability' => false,
            'run_sync' => false,
        ], JSON_THROW_ON_ERROR),
        'updated_at' => now(),
    ]);

    $record = app(PluginUiTableRegistry::class)
        ->newModel($plugin, $linksTable)
        ->newQuery()
        ->findOrFail($row->id);

    app(PluginUiTableRegistry::class)->clearRecordForDelete($record, $definition);

    $cleared = DB::table($linksTable)->where('id', $row->id)->first();

    expect(DB::table($linksTable)->count())->toBe(1)
        ->and((int) $cleared->playlist_id)->toBe($playlist->id)
        ->and($cleared->extension_plugin_profile_id)->toBeNull()
        ->and((bool) $cleared->enabled)->toBeFalse()
        ->and(json_decode((string) $cleared->settings, true))->toMatchArray([
            'run_availability' => true,
            'run_sync' => true,
        ]);
});

it('scopes plugin-declared result tables to a run and playlist', function () {
    $user = User::factory()->create(['permissions' => ['use_tools']]);
    $this->actingAs($user);

    $fixture = seedDeclaredRunResultRows($user);
    $plugin = $fixture['plugin'];
    $playlist = $fixture['playlist'];
    $run = $fixture['run'];

    expect(app(PluginUiTableRegistry::class)->runScopedTablesFor($plugin))->toHaveCount(1);

    Livewire::test(PluginTableInline::class, [
        'record' => $plugin,
        'tableId' => 'run_results',
        'runId' => $run->id,
        'playlistId' => $playlist->id,
        'readOnly' => true,
        'showHeading' => true,
    ])
        ->assertOk()
        ->assertSee('Run Results')
        ->assertSee('Included Result')
        ->assertSee('CSV')
        ->assertSee('JSON')
        ->assertDontSee('Other Run Result')
        ->assertDontSee('Other Playlist Result');
});

it('honors plugin-declared export formats for inline tables and downloads', function () {
    $user = User::factory()->create(['permissions' => ['use_tools']]);
    $this->actingAs($user);

    $fixture = seedDeclaredRunResultRows($user);
    $plugin = $fixture['plugin'];
    $playlist = $fixture['playlist'];
    $run = $fixture['run'];
    $schema = $plugin->schema_definition;
    data_set($schema, 'ui_tables.0.export_formats', ['csv']);
    $plugin->update(['schema_definition' => $schema]);

    Livewire::test(PluginTableInline::class, [
        'record' => $plugin->fresh(),
        'tableId' => 'run_results',
        'runId' => $run->id,
        'playlistId' => $playlist->id,
        'readOnly' => true,
        'showHeading' => true,
    ])
        ->assertOk()
        ->assertSee('CSV')
        ->assertDontSee('JSON');

    $this->get(route('extension-plugins.tables.export', [
        'plugin' => $plugin,
        'table' => 'run_results',
        'format' => 'csv',
        'run' => $run->id,
        'playlist' => $playlist->id,
    ]))->assertOk();

    $this->get(route('extension-plugins.tables.export', [
        'plugin' => $plugin,
        'table' => 'run_results',
        'format' => 'json',
        'run' => $run->id,
        'playlist' => $playlist->id,
    ]))->assertNotFound();
});

it('can disable plugin-declared inline table exports', function () {
    $user = User::factory()->create(['permissions' => ['use_tools']]);
    $this->actingAs($user);

    $fixture = seedDeclaredRunResultRows($user);
    $plugin = $fixture['plugin'];
    $playlist = $fixture['playlist'];
    $run = $fixture['run'];
    $schema = $plugin->schema_definition;
    data_set($schema, 'ui_tables.0.export_formats', []);
    $plugin->update(['schema_definition' => $schema]);

    Livewire::test(PluginTableInline::class, [
        'record' => $plugin->fresh(),
        'tableId' => 'run_results',
        'runId' => $run->id,
        'playlistId' => $playlist->id,
        'readOnly' => true,
        'showHeading' => true,
    ])
        ->assertOk()
        ->assertDontSee('CSV')
        ->assertDontSee('JSON');

    $this->get(route('extension-plugins.tables.export', [
        'plugin' => $plugin,
        'table' => 'run_results',
        'format' => 'csv',
        'run' => $run->id,
        'playlist' => $playlist->id,
    ]))->assertNotFound();
});

it('streams plugin-declared result table exports on demand', function () {
    $user = User::factory()->create(['permissions' => ['use_tools']]);
    $this->actingAs($user);

    $fixture = seedDeclaredRunResultRows($user);
    $plugin = $fixture['plugin'];
    $playlist = $fixture['playlist'];
    $run = $fixture['run'];
    $expectedFilename = Str::slug(collect([
        $plugin->plugin_id ?: "plugin-{$plugin->id}",
        'run_results',
        "run-{$run->id}",
        "playlist-{$playlist->id}",
    ])->filter()->implode('-'));

    $csvResponse = $this->get(route('extension-plugins.tables.export', [
        'plugin' => $plugin,
        'table' => 'run_results',
        'format' => 'csv',
        'run' => $run->id,
        'playlist' => $playlist->id,
    ]))->assertOk();

    expect($csvResponse->headers->get('content-disposition'))
        ->toContain('attachment')
        ->toContain("{$expectedFilename}.csv");

    $csv = $csvResponse->streamedContent();
    expect($csv)
        ->toContain('Title')
        ->toContain('Included Result')
        ->not->toContain('Other Run Result')
        ->not->toContain('Other Playlist Result');

    $jsonResponse = $this->get(route('extension-plugins.tables.export', [
        'plugin' => $plugin,
        'table' => 'run_results',
        'format' => 'json',
        'run' => $run->id,
        'playlist' => $playlist->id,
    ]))->assertOk();

    expect($jsonResponse->headers->get('content-disposition'))
        ->toContain('attachment')
        ->toContain("{$expectedFilename}.json");

    $rows = json_decode($jsonResponse->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['title'])->toBe('Included Result')
        ->and($rows[0]['details'])->toBe(['reason' => 'matched']);
});

it('discovers declared result tables on plugin run detail pages', function () {
    $user = User::factory()->create(['permissions' => ['use_tools']]);
    $this->actingAs($user);

    $fixture = seedDeclaredRunResultRows($user);
    $plugin = $fixture['plugin'];
    $playlist = $fixture['playlist'];
    $run = $fixture['run'];

    $component = Livewire::test(ViewPluginRun::class, [
        'record' => $plugin->id,
        'run' => $run->id,
    ])
        ->assertOk()
        ->assertSee('Results ready.');

    expect($component->instance()->resultTables())->toHaveCount(1)
        ->and($component->instance()->scopedPlaylistId())->toBe($playlist->id);
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

it('treats delete_behavior delete as a standard delete with no clear applied', function () {
    $plugin = declaredTableUiPlugin();
    $definition = data_get($plugin->schema_definition, 'ui_tables.0');
    $definition['delete_behavior'] = 'delete';

    expect(app(PluginUiTableRegistry::class)->clearsRecordOnDelete($definition))->toBeFalse();
});

it('validates clear delete behavior declarations on ui_tables', function () {
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
                'delete_behavior' => 'clear',
                'columns' => [],
                'fields' => [],
            ]],
        ],
    ], '/tmp/test-plugin');

    $validator = app(PluginValidator::class);
    $method = new ReflectionMethod($validator, 'validateSchema');
    $errors = $method->invoke($validator, $manifest);

    expect($errors)->toContain('schema.ui_tables.0.delete_payload must be an object when delete_behavior is [clear].');
});

it('validates dynamic option provider declarations on ui_table columns', function () {
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
                'columns' => [[
                    'name' => 'source',
                    'options_provider' => '',
                    'depends_on' => ['provider', ''],
                ]],
                'fields' => [],
            ]],
        ],
    ], '/tmp/test-plugin');

    $validator = app(PluginValidator::class);
    $method = new ReflectionMethod($validator, 'validateSchema');
    $errors = $method->invoke($validator, $manifest);

    expect($errors)->toContain('schema.ui_tables.0.columns.0 options_provider must be a non-empty string')
        ->and($errors)->toContain('schema.ui_tables.0.columns.0 depends_on must contain only non-empty strings');
});

it('validates ui_table export format declarations', function () {
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
                'export_formats' => ['csv', 'xlsx'],
                'columns' => [],
                'fields' => [],
            ]],
        ],
    ], '/tmp/test-plugin');

    $validator = app(PluginValidator::class);
    $method = new ReflectionMethod($validator, 'validateSchema');
    $errors = $method->invoke($validator, $manifest);

    expect($errors)->toContain('schema.ui_tables.0.export_formats must contain only supported formats: csv, json.');
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
