<?php

namespace App\Plugins;

use App\Models\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;
use InvalidArgumentException;
use Throwable;

class PluginSchemaMapper
{
    public function settingsComponents(?Plugin $plugin): array
    {
        if (! $plugin) {
            return [];
        }

        return $this->componentsForFields($plugin->settings_schema ?? [], 'settings.', plugin: $plugin);
    }

    public function actionComponents(Plugin $plugin, string $actionId): array
    {
        $action = $plugin->getActionDefinition($actionId);

        return $this->componentsForFields($action['fields'] ?? [], '', $plugin->settings ?? [], $plugin);
    }

    public function componentsForFieldDefinitions(array $fields, string $prefix = '', array $existing = [], ?Plugin $plugin = null): array
    {
        return $this->componentsForFields($fields, $prefix, $existing, $plugin);
    }

    public function settingsRules(?Plugin $plugin): array
    {
        if (! $plugin) {
            return [];
        }

        return $this->rulesForFields($plugin->settings_schema ?? [], 'settings.', $plugin);
    }

    public function actionRules(Plugin $plugin, string $actionId): array
    {
        $action = $plugin->getActionDefinition($actionId);

        return $this->rulesForFields($action['fields'] ?? [], '', $plugin);
    }

    public function defaultsForFields(array $fields, array $existing = []): array
    {
        $defaults = [];

        foreach ($fields as $field) {
            if (($field['type'] ?? null) === 'section') {
                $defaults = [
                    ...$defaults,
                    ...$this->defaultsForFields($field['fields'] ?? [], $existing),
                ];

                continue;
            }

            $fieldId = $field['id'] ?? null;
            if (! $fieldId) {
                continue;
            }

            $defaults[$fieldId] = Arr::get($existing, $fieldId, $field['default'] ?? null);
        }

        return $defaults;
    }

    private function componentsForFields(array $fields, string $prefix = '', array $existing = [], ?Plugin $plugin = null, ?array $dependencyResetTargets = null): array
    {
        $dependencyResetTargets ??= $this->dependencyResetTargets($fields, $prefix);

        return collect($fields)
            ->filter(fn (array $field): bool => ($field['type'] ?? null) === 'section' || filled($field['id'] ?? null))
            ->map(fn (array $field) => $this->componentForField($field, $prefix, $existing, $plugin, $dependencyResetTargets))
            ->values()
            ->all();
    }

    private function componentForField(array $field, string $prefix = '', array $existing = [], ?Plugin $plugin = null, array $dependencyResetTargets = [])
    {
        $type = $field['type'] ?? 'text';

        if ($type === 'section') {
            return $this->sectionComponent($field, $prefix, $existing, $plugin, $dependencyResetTargets);
        }

        $label = $field['label'] ?? Str::headline((string) ($field['id'] ?? 'value'));
        $helperText = $field['helper_text'] ?? null;
        $required = (bool) ($field['required'] ?? false);

        $name = $prefix.($field['id'] ?? '');
        $defaultKey = $field['default_from_setting'] ?? ($field['id'] ?? '');
        $default = Arr::get($existing, $defaultKey, $field['default'] ?? null);

        $component = match ($type) {
            'boolean' => Toggle::make($name),
            'number' => TextInput::make($name)->numeric(),
            'textarea' => Textarea::make($name)->rows(4),
            'tags' => TagsInput::make($name)->splitKeys(['Tab', 'Return']),
            'select' => $this->selectComponent($name, $field, $plugin, $prefix),
            'model_select' => $this->modelSelectComponent($name, $field),
            'table_select' => $this->tableSelectComponent($name, $field, $plugin),
            'text' => TextInput::make($name),
            default => throw new InvalidArgumentException("Unsupported plugin field type [{$type}]"),
        };

        if ($type === 'text' && (bool) ($field['secret'] ?? false)) {
            $component->password()->revealable();
        }

        if (($targets = $dependencyResetTargets[$name] ?? []) !== []) {
            $component->live();
            $component->afterStateUpdated(function (Set $set) use ($targets): void {
                foreach ($targets as $target) {
                    $set($target, null);
                }
            });
        }

        return $component
            ->label($label)
            ->default($default)
            ->helperText($helperText)
            ->required($required);
    }

    /**
     * Build a Filament Section component for a `section` field definition.
     * Nested sections (sections within sections) are fully supported — each section's
     * `fields` array is processed recursively through componentsForFields(), so any depth
     * of nesting works for both rendering and defaults/rules flattening.
     */
    private function sectionComponent(array $field, string $prefix = '', array $existing = [], ?Plugin $plugin = null, array $dependencyResetTargets = []): Section
    {
        $label = $field['label'] ?? Str::headline((string) ($field['id'] ?? 'Section'));
        $description = $field['description'] ?? $field['helper_text'] ?? null;
        $columns = (int) ($field['columns'] ?? 1);

        $section = Section::make($label)
            ->compact((bool) ($field['compact'] ?? true)) // Sections default to compact mode since they are often used for logical grouping within a form, but allow this to be overridden for more visual separation when needed.
            ->schema($this->componentsForFields($field['fields'] ?? [], $prefix, $existing, $plugin, $dependencyResetTargets))
            ->columnSpanFull();

        if (filled($description)) {
            $section->description($description);
        }

        if (! empty($field['icon'])) {
            $section->icon((string) $field['icon']);
        }

        if ((bool) ($field['collapsible'] ?? false)) {
            $section->collapsible();
            $section->collapsed((bool) ($field['collapsed'] ?? false));
        }

        if ($columns > 1) {
            $section->columns($columns);
        }

        return $section;
    }

    private function selectComponent(string $name, array $field, ?Plugin $plugin, string $prefix): Select
    {
        $optionsProvider = trim((string) ($field['options_provider'] ?? ''));

        $select = Select::make($name)
            ->options($optionsProvider !== ''
                ? fn (Get $get): array => $this->dynamicSelectOptions($plugin, $optionsProvider, $field, $prefix, $get)
                : $this->staticSelectOptions($field))
            ->searchable()
            ->placeholder($this->selectPlaceholder($field))
            ->selectablePlaceholder(! (bool) ($field['required'] ?? false));

        if ($optionsProvider !== '') {
            $select->live();
        }

        if ((bool) ($field['multiple'] ?? false)) {
            $select->multiple();
        }

        return $select;
    }

    private function modelSelectComponent(string $name, array $field): Select
    {
        $modelClass = $field['model'] ?? null;
        $labelAttribute = $field['label_attribute'] ?? 'name';
        $multiple = (bool) ($field['multiple'] ?? false);

        if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException("Invalid model_select model for [{$name}]");
        }

        $select = Select::make($name)
            ->searchable()
            ->preload()
            ->placeholder($this->selectPlaceholder($field))
            ->selectablePlaceholder(! (bool) ($field['required'] ?? false))
            ->options(function () use ($field, $modelClass, $labelAttribute) {
                $query = $modelClass::query();

                if (($field['scope'] ?? null) === 'owned' && auth()->check() && $query->getModel()->isFillable('user_id')) {
                    $query->where('user_id', auth()->id());
                }

                return $query
                    ->orderBy($labelAttribute)
                    ->limit(200)
                    ->pluck($labelAttribute, 'id')
                    ->toArray();
            });

        if ($multiple) {
            $select->multiple();
        }

        return $select;
    }

    private function tableSelectComponent(string $name, array $field, ?Plugin $plugin): Select
    {
        $multiple = (bool) ($field['multiple'] ?? false);

        $select = Select::make($name)
            ->searchable()
            ->preload()
            ->placeholder($this->selectPlaceholder($field))
            ->selectablePlaceholder(! (bool) ($field['required'] ?? false))
            ->options(fn (): array => $plugin
                ? app(PluginUiTableRegistry::class)->lookupOptions($plugin, [
                    ...$field,
                    'key_column' => $field['value_column'] ?? 'id',
                    'enabled_only' => $field['enabled_only'] ?? true,
                ])
                : []);

        if ($multiple) {
            $select->multiple();
        }

        return $select;
    }

    private function selectPlaceholder(array $field): string
    {
        return (string) ($field['placeholder'] ?? ((bool) ($field['required'] ?? false) ? __('Select an option') : __('None')));
    }

    private function rulesForFields(array $fields, string $prefix = '', ?Plugin $plugin = null): array
    {
        $rules = [];

        foreach ($fields as $field) {
            if (($field['type'] ?? null) === 'section') {
                $rules = [
                    ...$rules,
                    ...$this->rulesForFields($field['fields'] ?? [], $prefix, $plugin),
                ];

                continue;
            }

            $fieldId = $field['id'] ?? null;
            if (! $fieldId) {
                continue;
            }

            $name = $prefix.$fieldId;
            $required = (bool) ($field['required'] ?? false);
            $type = $field['type'] ?? 'text';
            $multiple = (bool) ($field['multiple'] ?? false);
            $isMultiSelect = $multiple && $type === 'select';
            $isMultiModelSelect = $multiple && $type === 'model_select';
            $isMultiTableSelect = $multiple && $type === 'table_select';

            if ($isMultiModelSelect || $isMultiTableSelect) {
                // Parent rule: nullable array (or required with at least one item).
                $rules[$name] = [$required ? 'required' : 'nullable', 'array'];
                if ($required) {
                    $rules[$name][] = 'min:1';
                }
                // Per-item rule applied via wildcard.
                $rules[$name.'.*'] = $isMultiModelSelect
                    ? ['integer', $this->modelSelectExistsRule($field)]
                    : ($plugin ? ['integer', $this->tableSelectExistsRule($field, $plugin)] : ['integer']);

                continue;
            }

            if ($isMultiSelect) {
                $rules[$name] = [$required ? 'required' : 'nullable', 'array'];
                if ($required) {
                    $rules[$name][] = 'min:1';
                }
                $rules[$name.'.*'] = $this->selectValueRules($field);

                continue;
            }

            if ($type === 'tags') {
                $rules[$name] = [$required ? 'required' : 'nullable', 'array'];
                $rules[$name.'.*'] = ['string'];

                continue;
            }

            $fieldRules = [$required ? 'required' : 'nullable'];

            $fieldRules = [
                ...$fieldRules,
                ...match ($type) {
                    'boolean' => ['boolean'],
                    'number' => ['numeric'],
                    'textarea', 'text' => ['string'],
                    'select' => $this->selectValueRules($field),
                    'model_select' => ['integer', $this->modelSelectExistsRule($field)],
                    'table_select' => $plugin ? ['integer', $this->tableSelectExistsRule($field, $plugin)] : ['integer'],
                    'tags' => ['string'],
                    default => ['string'],
                },
            ];

            $rules[$name] = $fieldRules;
        }

        return $rules;
    }

    /**
     * @return array<int, string|ValidationRule|In>
     */
    private function selectValueRules(array $field): array
    {
        $rules = ['string'];

        if (blank($field['options_provider'] ?? null)) {
            $rules[] = Rule::in(array_keys($this->staticSelectOptions($field)));
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    private function staticSelectOptions(array $field): array
    {
        return collect($field['options'] ?? [])
            ->mapWithKeys(fn (mixed $label, mixed $value): array => [(string) $value => (string) $label])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function dynamicSelectOptions(?Plugin $plugin, string $provider, array $field, string $prefix, Get $get): array
    {
        if (! $plugin) {
            return [];
        }

        try {
            return app(PluginManager::class)->selectOptions(
                $plugin,
                $provider,
                $this->optionProviderState($field, $prefix, $get),
                $field,
            );
        } catch (Throwable $e) {
            Log::warning('Plugin dynamic select options failed.', [
                'plugin_id' => $plugin->plugin_id,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function optionProviderState(array $field, string $prefix, Get $get): array
    {
        $state = [];

        foreach ($this->dependsOn($field, $prefix) as $dependency) {
            $value = $get($dependency);

            $state[$dependency] = $value;
            Arr::set($state, $dependency, $value);
        }

        return $state;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function dependencyResetTargets(array $fields, string $prefix): array
    {
        $targets = [];

        $this->collectDependencyResetTargets($fields, $prefix, $targets);

        return collect($targets)
            ->map(fn (array $values): array => array_values(array_unique($values)))
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, array<int, string>>  $targets
     */
    private function collectDependencyResetTargets(array $fields, string $prefix, array &$targets): void
    {
        foreach ($fields as $field) {
            if (($field['type'] ?? null) === 'section') {
                $this->collectDependencyResetTargets($field['fields'] ?? [], $prefix, $targets);

                continue;
            }

            $fieldId = $field['id'] ?? null;
            if (! $fieldId || blank($field['options_provider'] ?? null)) {
                continue;
            }

            $fieldName = $prefix.$fieldId;
            foreach ($this->dependsOn($field, $prefix) as $dependency) {
                $targets[$dependency][] = $fieldName;
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function dependsOn(array $field, string $prefix): array
    {
        return collect(Arr::wrap($field['depends_on'] ?? []))
            ->filter(fn (mixed $dependency): bool => is_string($dependency) && trim($dependency) !== '')
            ->map(fn (string $dependency): string => $this->qualifiedDependencyName(trim($dependency), $prefix))
            ->values()
            ->all();
    }

    private function qualifiedDependencyName(string $dependency, string $prefix): string
    {
        if ($prefix !== '' && ! str_contains($dependency, '.') && ! str_starts_with($dependency, $prefix)) {
            return $prefix.$dependency;
        }

        return $dependency;
    }

    private function modelSelectExistsRule(array $field): Exists
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $field['model'];
        $model = app($modelClass);
        $rule = Rule::exists($model->getTable(), 'id');

        if (($field['scope'] ?? null) === 'owned' && auth()->check() && ! auth()->user()->isAdmin() && Schema::hasColumn($model->getTable(), 'user_id')) {
            $rule->where(fn ($query) => $query->where('user_id', auth()->id()));
        }

        return $rule;
    }

    private function tableSelectExistsRule(array $field, Plugin $plugin): Exists
    {
        $tableName = app(PluginUiTableRegistry::class)->tableNameFor(
            $plugin,
            (string) ($field['table'] ?? ''),
            allowHostTable: false,
        );

        $keyColumn = (string) ($field['value_column'] ?? 'id');
        // Use a guaranteed-invalid table name when the table cannot be resolved so the rule always fails safely.
        $rule = Rule::exists($tableName ?? '_invalid_table_', $keyColumn);

        if ((bool) ($field['scope_plugin'] ?? false) && $tableName && Schema::hasColumn($tableName, 'extension_plugin_id')) {
            $rule->where('extension_plugin_id', $plugin->id);
        }

        if ((bool) ($field['enabled_only'] ?? false) && $tableName && Schema::hasColumn($tableName, 'enabled')) {
            $rule->where('enabled', true);
        }

        return $rule;
    }
}
