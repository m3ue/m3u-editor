<?php

namespace App\Plugins;

use App\Models\Plugin;
use App\Models\PluginTableRecord;
use Filament\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PluginUiTableRegistry
{
    public const EXPORT_FORMATS = ['csv', 'json'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tablesFor(Plugin $plugin): array
    {
        return collect(data_get($plugin->schema_definition, 'ui_tables', []))
            ->filter(fn (mixed $table): bool => is_array($table) && filled($table['id'] ?? null) && filled($table['table'] ?? null))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function tableFor(Plugin $plugin, string $tableId): ?array
    {
        return collect($this->tablesFor($plugin))
            ->first(fn (array $table): bool => ($table['id'] ?? null) === $tableId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function runScopedTablesFor(Plugin $plugin): array
    {
        return collect($this->tablesFor($plugin))
            ->filter(fn (array $table): bool => $this->hasColumns((string) ($table['table'] ?? ''), ['extension_plugin_run_id']))
            ->values()
            ->all();
    }

    public function tableNameFor(Plugin $plugin, string $tableIdOrName, bool $allowHostTable = false): ?string
    {
        $table = $this->tableFor($plugin, $tableIdOrName);
        if ($table) {
            return (string) $table['table'];
        }

        if (in_array($tableIdOrName, $this->ownedTables($plugin), true)) {
            return $tableIdOrName;
        }

        return $allowHostTable && app(PluginUiRequestCache::class)->hasTable($tableIdOrName) ? $tableIdOrName : null;
    }

    public function newModel(Plugin $plugin, string $tableName): PluginTableRecord
    {
        return PluginTableRecord::forTable(
            $tableName,
            $this->jsonColumnsFor($plugin, $tableName),
            $this->usesTimestamps($plugin, $tableName),
        );
    }

    public function applyTableScope(
        EloquentBuilder|QueryBuilder $query,
        Plugin $plugin,
        string $tableName,
        ?int $runId = null,
        ?int $playlistId = null,
    ): EloquentBuilder|QueryBuilder {
        return $query
            ->when(
                $tableName !== '' && app(PluginUiRequestCache::class)->hasColumn($tableName, 'extension_plugin_id'),
                fn (EloquentBuilder|QueryBuilder $query) => $query->where('extension_plugin_id', $plugin->id),
            )
            ->when(
                $runId !== null && $tableName !== '' && app(PluginUiRequestCache::class)->hasColumn($tableName, 'extension_plugin_run_id'),
                fn (EloquentBuilder|QueryBuilder $query) => $query->where('extension_plugin_run_id', $runId),
            )
            ->when(
                $playlistId !== null && $tableName !== '' && app(PluginUiRequestCache::class)->hasColumn($tableName, 'playlist_id'),
                fn (EloquentBuilder|QueryBuilder $query) => $query->where('playlist_id', $playlistId),
            );
    }

    /**
     * @return array<string, string>
     */
    public function distinctColumnOptions(
        Plugin $plugin,
        string $tableName,
        string $column,
        ?int $runId = null,
        ?int $playlistId = null,
    ): array {
        if (! $this->hasColumns($tableName, [$column])) {
            return [];
        }

        return $this->applyTableScope(DB::table($tableName), $plugin, $tableName, $runId, $playlistId)
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->limit(250)
            ->pluck($column)
            ->filter(fn (mixed $value): bool => (string) $value !== '')
            ->mapWithKeys(fn (mixed $value): array => [(string) $value => Str::headline((string) $value)])
            ->all();
    }

    public function lookupLabel(Plugin $plugin, array $lookup, mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $query = $this->lookupQuery($plugin, $lookup);
        if (! $query) {
            return null;
        }

        $labelColumn = (string) ($lookup['label_column'] ?? 'name');
        $query->where((string) ($lookup['key_column'] ?? 'id'), $value)->select($labelColumn)->limit(1);

        return app(PluginUiRequestCache::class)->queryValue($query, fn () => $query->value($labelColumn));
    }

    /**
     * @return array<string, string>
     */
    public function lookupOptions(Plugin $plugin, array $lookup): array
    {
        $query = $this->lookupQuery($plugin, $lookup);
        if (! $query) {
            return [];
        }

        $labelColumn = (string) ($lookup['label_column'] ?? 'name');
        $keyColumn = (string) ($lookup['key_column'] ?? 'id');

        $query->orderBy($labelColumn)->limit(min((int) ($lookup['limit'] ?? 500), 500))->select([$labelColumn, $keyColumn]);

        return app(PluginUiRequestCache::class)->queryOptions($query, fn (): array => $query
            ->pluck($labelColumn, $keyColumn)
            ->mapWithKeys(fn (mixed $label, mixed $value): array => [(string) $value => (string) $label])
            ->all());
    }

    public function clearsRecordOnDelete(array $definition): bool
    {
        return ($definition['delete_behavior'] ?? null) === 'clear';
    }

    public function clearRecordForDelete(PluginTableRecord $record, array $definition): PluginTableRecord
    {
        $payload = is_array($definition['delete_payload'] ?? null)
            ? $definition['delete_payload']
            : [];

        $record->update($this->expandedPayload($payload));

        return $record;
    }

    public function decorateClearAction(DeleteAction $action, array $definition, string $modelLabel): DeleteAction
    {
        $label = (string) ($definition['delete_label'] ?? __('Clear :model', ['model' => $modelLabel]));
        $icon = (string) ($definition['delete_icon'] ?? 'heroicon-o-x-mark');

        return $action
            ->label($label)
            ->icon($icon)
            ->color((string) ($definition['delete_color'] ?? 'gray'))
            ->modalHeading($label)
            ->modalIcon($icon)
            ->modalDescription((string) ($definition['delete_description'] ?? __('This will clear the saved configuration for this row without removing it from the table.')))
            ->modalSubmitActionLabel((string) ($definition['delete_submit_label'] ?? __('Clear')))
            ->successNotificationTitle((string) ($definition['delete_success_message'] ?? __(':model cleared', ['model' => $modelLabel])))
            ->using(fn (PluginTableRecord $record): PluginTableRecord => $this->clearRecordForDelete($record, $definition));
    }

    public function columnDisplayState(Plugin $plugin, PluginTableRecord $record, array $column): mixed
    {
        $valuePath = (string) ($column['lookup']['source_column'] ?? $column['name']);
        $attributes = array_map(
            fn (string $path): string => Str::before($path, '.'),
            [$valuePath, ...$this->dependsOn($column)],
        );
        if ($record->getVisible() !== []) {
            $attributes = array_intersect($attributes, $record->getVisible());
        }

        // Keep Eloquent's date/enum serialization without decoding unrelated JSON payloads.
        $state = $attributes === [] ? [] : (clone $record)->setVisible($attributes)->toArray();
        if (! empty($column['lookup']) && is_array($column['lookup'])) {
            $value = data_get($state, $valuePath);

            return $this->lookupLabel($plugin, $column['lookup'], $value);
        }

        $value = data_get($state, $valuePath);

        if (is_scalar($value)) {
            $options = $this->columnOptions($plugin, $column, $state);

            if ($options !== []) {
                return $options[(string) $value] ?? $value;
            }
        }

        return is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : $value;
    }

    /**
     * @param  array<string, mixed>  $recordState
     * @return array<string, string>
     */
    public function columnOptions(Plugin $plugin, array $column, array $recordState = []): array
    {
        $optionsProvider = trim((string) ($column['options_provider'] ?? ''));

        if ($optionsProvider !== '') {
            return $this->dynamicColumnOptions($plugin, $optionsProvider, $column, $recordState);
        }

        if (is_array($column['options'] ?? null)) {
            return $this->staticOptions($column['options']);
        }

        return is_array($column['lookup'] ?? null)
            ? $this->lookupOptions($plugin, $column['lookup'])
            : [];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    public function exportFormatsFor(array $definition): array
    {
        if (! array_key_exists('export_formats', $definition)) {
            return self::EXPORT_FORMATS;
        }

        return collect(Arr::wrap($definition['export_formats']))
            ->filter(fn (mixed $format): bool => is_string($format))
            ->map(fn (string $format): string => strtolower(trim($format)))
            ->filter(fn (string $format): bool => in_array($format, self::EXPORT_FORMATS, true))
            ->unique()
            ->values()
            ->all();
    }

    public function prefillRows(Plugin $plugin, array $definition): void
    {
        $prefill = $definition['prefill'] ?? null;
        $source = is_array($prefill) ? ($prefill['source'] ?? null) : null;

        if (! is_array($prefill) || ! is_array($source)) {
            return;
        }

        $targetTable = (string) ($definition['table'] ?? '');
        // allowHostTable: trusted plugins may read from host tables (e.g. playlists) as a prefill source.
        $sourceTable = $this->tableNameFor($plugin, (string) ($source['table'] ?? ''), allowHostTable: true);
        $sourceKey = (string) ($source['key_column'] ?? 'id');
        $targetColumn = (string) ($prefill['target_column'] ?? '');

        if (! $sourceTable || ! $this->hasColumns($targetTable, [$targetColumn]) || ! $this->hasColumns($sourceTable, [$sourceKey])) {
            return;
        }

        $sourceRows = $this->prefillSourceRows($sourceTable, $sourceKey, $source);
        if ($sourceRows->isEmpty()) {
            return;
        }

        $sourceValues = $sourceRows->pluck($sourceKey);
        $existing = DB::table($targetTable)
            ->when(app(PluginUiRequestCache::class)->hasColumn($targetTable, 'extension_plugin_id'), fn ($query) => $query->where('extension_plugin_id', $plugin->id))
            ->where(fn (QueryBuilder $query) => $query
                ->whereIn($targetColumn, $sourceValues)
                ->when($sourceValues->containsStrict(null), fn (QueryBuilder $query) => $query->orWhereNull($targetColumn)))
            ->pluck($targetColumn)
            ->mapWithKeys(fn (mixed $value): array => [(string) $value => true])
            ->all();

        $defaults = is_array($prefill['defaults'] ?? null) ? $prefill['defaults'] : [];
        foreach ($sourceRows as $sourceRow) {
            $sourceValue = $sourceRow->{$sourceKey};
            if (isset($existing[(string) $sourceValue])) {
                continue;
            }

            $this->newModel($plugin, $targetTable)->newQuery()->create(
                $this->prefillPayload($plugin, $targetTable, $targetColumn, $sourceValue, $sourceRow, $source, $defaults)
            );

            $existing[(string) $sourceValue] = true;
        }
    }

    /**
     * @return array<int, string>
     */
    public function jsonColumnsFor(Plugin $plugin, string $tableName): array
    {
        return $this->columnsFor($plugin, $tableName)
            ->filter(fn (array $column): bool => ($column['type'] ?? null) === 'json' && filled($column['name'] ?? null))
            ->pluck('name')
            ->values()
            ->all();
    }

    public function usesTimestamps(Plugin $plugin, string $tableName): bool
    {
        return $this->columnsFor($plugin, $tableName)->contains(fn (array $column): bool => ($column['type'] ?? null) === 'timestamps')
            || (app(PluginUiRequestCache::class)->hasColumn($tableName, 'created_at') && app(PluginUiRequestCache::class)->hasColumn($tableName, 'updated_at'));
    }

    /**
     * @return array<int, string>
     */
    public function ownedTables(Plugin $plugin): array
    {
        return collect([
            ...Arr::wrap(data_get($plugin->data_ownership, 'tables', [])),
            ...collect(data_get($plugin->schema_definition, 'tables', []))->pluck('name')->all(),
        ])
            ->filter(fn (mixed $table): bool => is_string($table) && $table !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function lookupQuery(Plugin $plugin, array $lookup): ?QueryBuilder
    {
        // allowHostTable: trusted plugins may read from host tables (e.g. playlists) for lookup options and labels.
        $tableName = $this->tableNameFor($plugin, (string) ($lookup['table'] ?? ''), allowHostTable: true);
        if (! $tableName) {
            return null;
        }

        return DB::table($tableName)
            ->when(
                (bool) ($lookup['scope_plugin'] ?? false) && app(PluginUiRequestCache::class)->hasColumn($tableName, 'extension_plugin_id'),
                fn (QueryBuilder $query) => $query->where('extension_plugin_id', $plugin->id),
            )
            ->when(
                (bool) ($lookup['enabled_only'] ?? false) && app(PluginUiRequestCache::class)->hasColumn($tableName, 'enabled'),
                fn (QueryBuilder $query) => $query->where('enabled', true),
            );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function expandedPayload(array $payload): array
    {
        $expanded = [];

        foreach ($payload as $key => $value) {
            data_set($expanded, (string) $key, $value);
        }

        return $expanded;
    }

    /**
     * @param  array<mixed>  $options
     * @return array<string, string>
     */
    private function staticOptions(array $options): array
    {
        return collect($options)
            ->mapWithKeys(fn (mixed $label, mixed $value): array => [(string) $value => (string) $label])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $column
     * @param  array<string, mixed>  $recordState
     * @return array<string, string>
     */
    private function dynamicColumnOptions(Plugin $plugin, string $provider, array $column, array $recordState): array
    {
        try {
            return app(PluginManager::class)->selectOptions(
                $plugin,
                $provider,
                $this->columnOptionProviderState($column, $recordState),
                $column,
            );
        } catch (Throwable $e) {
            Log::warning('Plugin dynamic column options failed.', [
                'plugin_id' => $plugin->plugin_id,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $column
     * @param  array<string, mixed>  $recordState
     * @return array<string, mixed>
     */
    private function columnOptionProviderState(array $column, array $recordState): array
    {
        $state = [];

        foreach ($this->dependsOn($column) as $dependency) {
            $value = data_get($recordState, $dependency);

            $state[$dependency] = $value;
            Arr::set($state, $dependency, $value);
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $column
     * @return array<int, string>
     */
    private function dependsOn(array $column): array
    {
        return collect(Arr::wrap($column['depends_on'] ?? []))
            ->filter(fn (mixed $dependency): bool => is_string($dependency) && trim($dependency) !== '')
            ->map(fn (string $dependency): string => trim($dependency))
            ->values()
            ->all();
    }

    private function columnsFor(Plugin $plugin, string $tableName): Collection
    {
        $table = collect(data_get($plugin->schema_definition, 'tables', []))
            ->first(fn (array $table): bool => ($table['name'] ?? null) === $tableName);

        return collect($table['columns'] ?? []);
    }

    private function hasColumns(string $tableName, array $columns): bool
    {
        return $tableName !== ''
            && collect($columns)->every(fn (string $column): bool => $column !== '' && app(PluginUiRequestCache::class)->hasColumn($tableName, $column));
    }

    private function prefillSourceRows(string $sourceTable, string $sourceKey, array $source): Collection
    {
        $userColumn = (string) ($source['user_column'] ?? 'user_id');
        $sourceColumns = [$sourceKey, ...(app(PluginUiRequestCache::class)->hasColumn($sourceTable, $userColumn) ? [$userColumn] : [])];
        $query = DB::table($sourceTable)->select(array_values(array_unique($sourceColumns)));

        if (($source['scope'] ?? null) === 'owned' && auth()->check() && app(PluginUiRequestCache::class)->hasColumn($sourceTable, $userColumn)) {
            $query->where($userColumn, auth()->id());
        }

        $orderColumn = (string) ($source['order_column'] ?? $sourceKey);
        if (app(PluginUiRequestCache::class)->hasColumn($sourceTable, $orderColumn)) {
            $query->orderBy($orderColumn);
        }

        $query->limit(config('plugins.prefill_max_source_rows', 1000));

        return $query->get();
    }

    private function prefillPayload(Plugin $plugin, string $targetTable, string $targetColumn, mixed $sourceValue, object $sourceRow, array $source, array $defaults): array
    {
        $payload = [$targetColumn => $sourceValue];
        $userColumn = (string) ($source['user_column'] ?? 'user_id');

        if (app(PluginUiRequestCache::class)->hasColumn($targetTable, 'extension_plugin_id')) {
            $payload['extension_plugin_id'] = $plugin->id;
        }

        if (app(PluginUiRequestCache::class)->hasColumn($targetTable, 'user_id')) {
            $payload['user_id'] = $sourceRow->{$userColumn} ?? auth()->id();
        }

        foreach ($defaults as $key => $value) {
            data_set($payload, (string) $key, $value);
        }

        return $payload;
    }
}
