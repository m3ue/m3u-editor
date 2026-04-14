<?php

namespace App\Plugins;

use App\Models\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use InvalidArgumentException;

class PluginSchemaMapper
{
    public function settingsComponents(?Plugin $plugin): array
    {
        if (! $plugin) {
            return [];
        }

        return $this->componentsForFields($plugin->settings_schema ?? [], 'settings.');
    }

    public function actionComponents(Plugin $plugin, string $actionId): array
    {
        $action = $plugin->getActionDefinition($actionId);

        return $this->componentsForFields($action['fields'] ?? [], '', $plugin->settings ?? []);
    }

    public function settingsRules(?Plugin $plugin): array
    {
        if (! $plugin) {
            return [];
        }

        return $this->rulesForFields($plugin->settings_schema ?? [], 'settings.');
    }

    public function actionRules(Plugin $plugin, string $actionId): array
    {
        $action = $plugin->getActionDefinition($actionId);

        return $this->rulesForFields($action['fields'] ?? []);
    }

    public function defaultsForFields(array $fields, array $existing = []): array
    {
        $defaults = [];

        foreach ($fields as $field) {
            $fieldId = $field['id'] ?? null;
            if (! $fieldId) {
                continue;
            }

            $defaults[$fieldId] = Arr::get($existing, $fieldId, $field['default'] ?? null);
        }

        return $defaults;
    }

    private function componentsForFields(array $fields, string $prefix = '', array $existing = []): array
    {
        return collect($fields)
            ->filter(fn (array $field): bool => filled($field['id'] ?? null))
            ->map(fn (array $field) => $this->componentForField($field, $prefix, $existing))
            ->all();
    }

    private function componentForField(array $field, string $prefix = '', array $existing = [])
    {
        $name = $prefix.($field['id'] ?? '');
        $type = $field['type'] ?? 'text';
        $label = $field['label'] ?? Str::headline((string) ($field['id'] ?? 'value'));
        $helperText = $field['helper_text'] ?? null;
        $required = (bool) ($field['required'] ?? false);
        $defaultKey = $field['default_from_setting'] ?? ($field['id'] ?? '');
        $default = Arr::get($existing, $defaultKey, $field['default'] ?? null);

        $component = match ($type) {
            'boolean' => Toggle::make($name),
            'number' => TextInput::make($name)->numeric(),
            'textarea' => Textarea::make($name)->rows(4),
            'tags' => TagsInput::make($name)->splitKeys(['Tab', 'Return']),
            'select' => $this->staticSelectComponent($name, $field),
            'model_select' => $this->modelSelectComponent($name, $field),
            'text' => TextInput::make($name),
            default => throw new InvalidArgumentException("Unsupported plugin field type [{$type}]"),
        };

        return $component
            ->label($label)
            ->default($default)
            ->helperText($helperText)
            ->required($required);
    }

    private function staticSelectComponent(string $name, array $field): Select
    {
        $select = Select::make($name)
            ->options($field['options'] ?? [])
            ->searchable();

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

    private function rulesForFields(array $fields, string $prefix = ''): array
    {
        $rules = [];

        foreach ($fields as $field) {
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

            if ($isMultiModelSelect) {
                // Parent rule: nullable array (or required with at least one item).
                $rules[$name] = [$required ? 'required' : 'nullable', 'array'];
                if ($required) {
                    $rules[$name][] = 'min:1';
                }
                // Per-item rule applied via wildcard.
                $rules[$name.'.*'] = ['integer', $this->modelSelectExistsRule($field)];

                continue;
            }

            if ($isMultiSelect) {
                $rules[$name] = [$required ? 'required' : 'nullable', 'array'];
                if ($required) {
                    $rules[$name][] = 'min:1';
                }
                $rules[$name.'.*'] = ['string', Rule::in(array_keys($field['options'] ?? []))];

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
                    'select' => ['string', Rule::in(array_keys($field['options'] ?? []))],
                    'model_select' => ['integer', $this->modelSelectExistsRule($field)],
                    'tags' => ['string'],
                    default => ['string'],
                },
            ];

            $rules[$name] = $fieldRules;
        }

        return $rules;
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
}
