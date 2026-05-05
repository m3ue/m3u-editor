<?php

use App\Models\Plugin;
use App\Plugins\PluginSchemaMapper;
use App\Plugins\PluginValidator;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('renders grouped plugin settings sections and keeps nested rules flat', function () {
    $plugin = new Plugin([
        'settings_schema' => [
            [
                'id' => 'core_setup',
                'type' => 'section',
                'label' => 'Core Setup',
                'description' => 'Primary plugin configuration.',
                'collapsible' => true,
                'collapsed' => false,
                'fields' => [
                    [
                        'id' => 'stream_profile_id',
                        'type' => 'number',
                        'label' => 'Stream Profile',
                        'default' => 5,
                    ],
                    [
                        'id' => 'channel_group',
                        'type' => 'text',
                        'label' => 'Channel Group',
                        'default' => 'Twitch Live',
                    ],
                ],
            ],
        ],
        'settings' => [
            'stream_profile_id' => 7,
        ],
    ]);

    $mapper = app(PluginSchemaMapper::class);

    $components = $mapper->settingsComponents($plugin);
    expect($components)->toHaveCount(1);
    expect($components[0])->toBeInstanceOf(Section::class);
    expect($components[0]->getHeading())->toBe('Core Setup');

    $defaults = $mapper->defaultsForFields($plugin->settings_schema, $plugin->settings);
    expect($defaults['stream_profile_id'])->toBe(7);
    expect($defaults['channel_group'])->toBe('Twitch Live');

    $rules = $mapper->settingsRules($plugin);
    expect($rules)->toHaveKey('settings.stream_profile_id');
    expect($rules)->toHaveKey('settings.channel_group');
});

it('rejects a section missing a label', function () {
    $mapper = app(PluginValidator::class);

    $fieldTypes = config('plugins.field_types');

    $errors = (new ReflectionMethod($mapper, 'validateFieldDefinition'))
        ->invoke($mapper, [
            'id' => 'my_section',
            'type' => 'section',
            'fields' => [['id' => 'foo', 'type' => 'text']],
        ], $fieldTypes, 'settings');

    expect($errors)->toContain('settings.my_section section fields require [label]');
});

it('rejects a section with an empty fields array', function () {
    $mapper = app(PluginValidator::class);

    $fieldTypes = config('plugins.field_types');

    $errors = (new ReflectionMethod($mapper, 'validateFieldDefinition'))
        ->invoke($mapper, [
            'id' => 'my_section',
            'type' => 'section',
            'label' => 'My Section',
            'fields' => [],
        ], $fieldTypes, 'settings');

    expect($errors)->toContain('settings.my_section section fields require non-empty [fields]');
});

it('rejects a section containing a non-array nested field', function () {
    $mapper = app(PluginValidator::class);

    $fieldTypes = config('plugins.field_types');

    $errors = (new ReflectionMethod($mapper, 'validateFieldDefinition'))
        ->invoke($mapper, [
            'id' => 'my_section',
            'type' => 'section',
            'label' => 'My Section',
            'fields' => ['not-an-array'],
        ], $fieldTypes, 'settings');

    expect($errors)->toContain('settings.my_section section fields must be objects');
});

it('allows a section without an id, using the label as the error path identifier', function () {
    $validator = app(PluginValidator::class);

    $fieldTypes = config('plugins.field_types');

    $errors = (new ReflectionMethod($validator, 'validateFieldDefinition'))
        ->invoke($validator, [
            'type' => 'section',
            'label' => 'Unlabelled Section',
            'fields' => [['id' => 'foo', 'type' => 'text']],
        ], $fieldTypes, 'settings');

    expect($errors)->toBe([]);
});

it('renders nested sections and flattens their fields for defaults and rules', function () {
    $plugin = new Plugin([
        'settings_schema' => [
            [
                'id' => 'outer',
                'type' => 'section',
                'label' => 'Outer Section',
                'fields' => [
                    [
                        'id' => 'top_level_field',
                        'type' => 'text',
                        'label' => 'Top Level',
                        'default' => 'top',
                    ],
                    [
                        'id' => 'inner',
                        'type' => 'section',
                        'label' => 'Inner Section',
                        'fields' => [
                            [
                                'id' => 'nested_field',
                                'type' => 'text',
                                'label' => 'Nested',
                                'default' => 'deep',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'settings' => [],
    ]);

    $mapper = app(PluginSchemaMapper::class);

    $components = $mapper->settingsComponents($plugin);
    expect($components)->toHaveCount(1);
    expect($components[0])->toBeInstanceOf(Section::class);
    expect($components[0]->getHeading())->toBe('Outer Section');

    $defaults = $mapper->defaultsForFields($plugin->settings_schema, []);
    expect($defaults)->toHaveKey('top_level_field', 'top')
        ->and($defaults)->toHaveKey('nested_field', 'deep');

    $rules = $mapper->settingsRules($plugin);
    expect($rules)->toHaveKey('settings.top_level_field')
        ->and($rules)->toHaveKey('settings.nested_field');
});

it('validates plugin manifests that use grouped settings sections', function () {
    $pluginId = 'grouped-schema-'.Str::lower(Str::random(6));
    $sourcePath = storage_path('app/testing-plugin-sources/'.$pluginId);
    $classSegment = Str::studly(str_replace('-', ' ', $pluginId));

    File::deleteDirectory($sourcePath);
    File::ensureDirectoryExists($sourcePath);

    $manifest = [
        'id' => $pluginId,
        'name' => 'Grouped Schema Fixture',
        'version' => '0.1.0',
        'description' => 'Temporary grouped settings fixture.',
        'api_version' => config('plugins.api_version'),
        'entrypoint' => 'Plugin.php',
        'class' => "AppLocalPlugins\\{$classSegment}\\Plugin",
        'capabilities' => [],
        'hooks' => [],
        'permissions' => [],
        'settings' => [
            [
                'id' => 'core_setup',
                'type' => 'section',
                'label' => 'Core Setup',
                'collapsible' => true,
                'collapsed' => false,
                'fields' => [
                    [
                        'id' => 'monitored_channels',
                        'type' => 'textarea',
                        'label' => 'Monitored Channels',
                        'default' => '',
                    ],
                    [
                        'id' => 'stream_profile_id',
                        'type' => 'number',
                        'label' => 'Stream Profile',
                        'required' => true,
                    ],
                ],
            ],
        ],
        'actions' => [],
        'schema' => [
            'tables' => [],
        ],
        'data_ownership' => [
            'plugin_id' => $pluginId,
            'table_prefix' => 'plugin_'.str_replace('-', '_', $pluginId).'_',
            'tables' => [],
            'directories' => [],
            'files' => [],
            'default_cleanup_policy' => 'preserve',
        ],
    ];

    $pluginSource = <<<PHP
<?php

namespace AppLocalPlugins\\{$classSegment};

use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;

class Plugin implements PluginInterface
{
    public function runAction(string \$action, array \$payload, PluginExecutionContext \$context): PluginActionResult
    {
        return PluginActionResult::success('ok');
    }
}
PHP;

    try {
        File::put(
            $sourcePath.'/plugin.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );
        File::put($sourcePath.'/Plugin.php', $pluginSource);

        $result = app(PluginValidator::class)->validatePath($sourcePath);

        expect($result->valid)->toBeTrue();
        expect($result->errors)->toBe([]);
    } finally {
        File::deleteDirectory($sourcePath);
    }
});
