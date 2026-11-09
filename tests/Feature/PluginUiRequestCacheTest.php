<?php

use App\Models\Playlist;
use App\Models\Plugin;
use App\Models\PluginTableRecord;
use App\Models\User;
use App\Plugins\PluginSchemaManager;
use App\Plugins\PluginSchemaMapper;
use App\Plugins\PluginUiRequestCache;
use App\Plugins\PluginUiTableRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

it('renders table cells without decoding unrelated JSON columns', function () {
    $record = new class extends PluginTableRecord
    {
        public static int $jsonDecodes = 0;

        public function fromJson($value, $asObject = false): mixed
        {
            self::$jsonDecodes++;

            return parent::fromJson($value, $asObject);
        }
    };
    $record->mergeCasts(['settings' => 'array', 'details' => 'array']);
    $record->setRawAttributes([
        'name' => 'Example',
        'status' => 'ready',
        'settings' => '{"source":"native"}',
        'details' => '{"diagnostics":["unrelated report data"]}',
        'created_at' => '2026-01-02 03:04:05',
    ]);
    $registry = app(PluginUiTableRegistry::class);
    $plugin = new Plugin;

    expect($registry->columnDisplayState($plugin, $record, ['name' => 'name']))->toBe('Example');
    expect($registry->columnDisplayState($plugin, $record, ['name' => 'status', 'options' => ['ready' => 'Ready']]))->toBe('Ready');
    expect($registry->columnDisplayState($plugin, $record, ['name' => 'created_at']))->toBe(Carbon::parse('2026-01-02 03:04:05')->toJSON());
    expect($record::$jsonDecodes)->toBe(0);

    expect($registry->columnDisplayState($plugin, $record, ['name' => 'settings.source']))->toBe('native');
    expect($registry->columnDisplayState($plugin, $record, ['name' => 'settings']))->toBe('{"source":"native"}');
    expect($record::$jsonDecodes)->toBe(2);
    expect($record->getVisible())->toBe([]);

    $record->setVisible(['name']);
    expect($registry->columnDisplayState($plugin, $record, ['name' => 'status']))->toBeNull();
    $record->makeHidden('name');
    expect($registry->columnDisplayState($plugin, $record, ['name' => 'name']))->toBeNull();
    expect($record->getVisible())->toBe(['name']);
});

it('shares equivalent owned model lookups without sharing another users options', function () {
    Bus::fake();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $mine = Playlist::factory()->for($owner)->createQuietly(['name' => 'Mine']);
    $theirs = Playlist::factory()->for($other)->createQuietly(['name' => 'Theirs']);
    $this->actingAs($owner);
    $fields = array_map(fn (string $id): array => [
        'id' => $id, 'label' => $id, 'type' => 'model_select', 'model' => Playlist::class, 'scope' => 'owned',
    ], ['source', 'preferred', 'backups']);

    DB::enableQueryLog();
    DB::flushQueryLog();
    foreach (app(PluginSchemaMapper::class)->componentsForFieldDefinitions($fields) as $field) {
        expect($field->getOptions())->toBe([$mine->id => 'Mine']);
    }
    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "playlists"')))->toHaveCount(1);

    $this->actingAs($other);
    expect(app(PluginSchemaMapper::class)->componentsForFieldDefinitions($fields)[0]->getOptions())->toBe([$theirs->id => 'Theirs']);
    auth()->forgetGuards();
    expect(app(PluginSchemaMapper::class)->componentsForFieldDefinitions($fields)[0]->getOptions())->toBe([]);
});

it('refreshes lookup results on a new request and after plugin table writes', function () {
    Schema::create('plugin_cache_options', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });
    $query = fn () => DB::table('plugin_cache_options')->orderBy('id')->select(['id', 'name']);
    $options = fn () => app(PluginUiRequestCache::class)->queryOptions($query(), fn () => $query()->pluck('name', 'id')->all());
    expect($options())->toBe([]);
    $record = PluginTableRecord::forTable('plugin_cache_options', timestamps: false);
    $record->fill(['name' => 'First'])->save();
    expect($options())->toBe([$record->id => 'First']);
    $record->update(['name' => 'Renamed']);
    expect($options())->toBe([$record->id => 'Renamed']);
    DB::table('plugin_cache_options')->where('id', $record->id)->update(['name' => 'Next request']);
    app()->instance('request', Request::create('/next'));
    expect($options())->toBe([$record->id => 'Next request']);
    $record->delete();
    expect($options())->toBe([]);
});

it('reads table columns once per request and refreshes after schema changes', function () {
    $manager = app(PluginSchemaManager::class);
    $schema = ['tables' => [['name' => 'plugin_cache_columns', 'columns' => [['type' => 'id', 'name' => 'id']]]]];
    expect(app(PluginUiRequestCache::class)->hasTable('plugin_cache_columns'))->toBeFalse();
    $manager->apply($schema);
    expect(app(PluginUiRequestCache::class)->hasTable('plugin_cache_columns'))->toBeTrue();
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(app(PluginUiRequestCache::class)->hasColumn('plugin_cache_columns', 'id'))->toBeTrue();
    $queries = DB::getQueryLog();
    expect($queries)->not->toBeEmpty();
    expect(app(PluginUiRequestCache::class)->hasColumn('plugin_cache_columns', 'name'))->toBeFalse();
    expect(DB::getQueryLog())->toBe($queries);
    $schema['tables'][0]['columns'][] = ['type' => 'string', 'name' => 'name', 'nullable' => true];
    $manager->apply($schema);
    expect(app(PluginUiRequestCache::class)->hasColumn('plugin_cache_columns', 'name'))->toBeTrue();
    $manager->purge($schema);
    expect(app(PluginUiRequestCache::class)->columns('plugin_cache_columns'))->toBe([]);
    expect(app(PluginUiRequestCache::class)->hasTable('plugin_cache_columns'))->toBeFalse();
});

it('does not share metadata or query results between database connections', function () {
    config(['database.connections.plugin_cache_other' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    foreach ([null, 'plugin_cache_other'] as $connection) {
        Schema::connection($connection)->create('plugin_cache_connections', function (Blueprint $table) use ($connection) {
            $table->id();
            $table->string($connection ? 'other_name' : 'name')->nullable();
        });
        DB::connection($connection)->table('plugin_cache_connections')->insert(['id' => $connection ? 2 : 1]);
        $query = DB::connection($connection)->table('plugin_cache_connections')->select('id');
        expect(app(PluginUiRequestCache::class)->queryOptions($query, fn () => $query->pluck('id')->all()))->toBe([$connection ? 2 : 1]);
    }
    expect(app(PluginUiRequestCache::class)->hasColumn('plugin_cache_connections', 'name'))->toBeTrue();
    expect(app(PluginUiRequestCache::class)->hasColumn('plugin_cache_connections', 'name', 'plugin_cache_other'))->toBeFalse();
});

it('refreshes metadata when schema application fails after creating an earlier table', function () {
    $cache = app(PluginUiRequestCache::class);
    expect($cache->hasTable('plugin_cache_partial'))->toBeFalse();
    expect(fn () => app(PluginSchemaManager::class)->apply(['tables' => [
        ['name' => 'plugin_cache_partial', 'columns' => [['type' => 'id', 'name' => 'id']]],
        ['name' => 'plugin_cache_invalid', 'columns' => [['type' => 'invalid', 'name' => 'id']]],
    ]]))->toThrow(RuntimeException::class);
    expect($cache->hasTable('plugin_cache_partial'))->toBeTrue();
    expect($cache->hasColumn('plugin_cache_partial', 'id'))->toBeTrue();
});

it('queries repeated host-table labels and table existence only once including missing labels', function () {
    Bus::fake();
    Http::preventStrayRequests();
    $playlist = Playlist::factory()->createQuietly(['name' => 'Shared playlist']);
    $plugin = new Plugin;
    $registry = app(PluginUiTableRegistry::class);
    $lookup = ['table' => 'playlists', 'label_column' => 'name'];
    DB::enableQueryLog();
    DB::flushQueryLog();

    for ($row = 0; $row < 20; $row++) {
        expect($registry->lookupLabel($plugin, $lookup, $playlist->id))->toBe('Shared playlist');
        expect($registry->lookupLabel($plugin, $lookup, -1))->toBeNull();
    }

    $queries = collect(DB::getQueryLog());
    expect($queries->filter(fn (array $query): bool => str_contains($query['query'], 'from "playlists"')))->toHaveCount(2);
    expect($queries)->toHaveCount(3);

    DB::table('playlists')->where('id', $playlist->id)->update(['name' => 'Renamed playlist']);
    app()->instance('request', Request::create('/next'));
    expect($registry->lookupLabel($plugin, $lookup, $playlist->id))->toBe('Renamed playlist');
});

it('keeps labels outside dropdown limits and refreshes labels after edits and schema changes', function () {
    $schema = ['tables' => [['name' => 'plugin_cache_labels', 'columns' => [
        ['name' => 'id', 'type' => 'id'],
        ['name' => 'extension_plugin_id', 'type' => 'bigInteger'],
        ['name' => 'name', 'type' => 'string'],
        ['name' => 'enabled', 'type' => 'boolean', 'default' => true],
        ['type' => 'timestamps'],
    ]]]];
    $manager = app(PluginSchemaManager::class);
    $manager->apply($schema);
    $registry = app(PluginUiTableRegistry::class);
    $plugin = (new Plugin)->forceFill(['id' => 1, 'schema_definition' => $schema]);
    $otherPlugin = (new Plugin)->forceFill(['id' => 2, 'schema_definition' => $schema]);
    $model = PluginTableRecord::forTable('plugin_cache_labels');
    $first = $model->newQuery()->create(['extension_plugin_id' => 1, 'name' => 'A']);
    $hidden = $model->newQuery()->create(['extension_plugin_id' => 1, 'name' => 'B', 'enabled' => false]);
    $outside = $model->newQuery()->create(['extension_plugin_id' => 1, 'name' => 'C']);
    $lookup = ['table' => 'plugin_cache_labels', 'scope_plugin' => true, 'limit' => 1];
    expect($registry->lookupOptions($plugin, $lookup))->toBe([$first->id => 'A']);
    expect($registry->lookupLabel($plugin, $lookup, $first->id))->toBe('A');
    expect($registry->lookupLabel($plugin, $lookup, $hidden->id))->toBe('B');
    expect($registry->lookupLabel($plugin, $lookup, $outside->id))->toBe('C');
    expect($registry->lookupLabel($otherPlugin, $lookup, $outside->id))->toBeNull();
    expect($registry->lookupLabel($plugin, [...$lookup, 'enabled_only' => true], $hidden->id))->toBeNull();
    $outside->update(['name' => 'Renamed']);
    expect($registry->lookupLabel($plugin, $lookup, $outside->id))->toBe('Renamed');
    $hidden->update(['enabled' => true]);
    expect($registry->lookupLabel($plugin, [...$lookup, 'enabled_only' => true], $hidden->id))->toBe('B');
    $outside->delete();
    expect($registry->lookupLabel($plugin, $lookup, $outside->id))->toBeNull();

    $manager->purge($schema);
    $manager->apply($schema);
    DB::table('plugin_cache_labels')->insert(['id' => $first->id, 'extension_plugin_id' => 1, 'name' => 'Replacement']);
    expect($registry->lookupOptions($plugin, $lookup))->toBe([$first->id => 'Replacement']);
    expect($registry->lookupLabel($plugin, $lookup, $first->id))->toBe('Replacement');
});
