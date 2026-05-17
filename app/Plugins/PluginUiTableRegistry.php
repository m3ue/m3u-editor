<?php

namespace App\Plugins;

use App\Models\Plugin;
use App\Models\PluginTableRecord;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PluginUiTableRegistry
{
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

    public function tableNameFor(Plugin $plugin, string $tableIdOrName, bool $allowHostTable = false): ?string
    {
        $table = $this->tableFor($plugin, $tableIdOrName);
        if ($table) {
            return (string) $table['table'];
        }

        if (in_array($tableIdOrName, $this->ownedTables($plugin), true)) {
            return $tableIdOrName;
        }

        return $allowHostTable && Schema::hasTable($tableIdOrName) ? $tableIdOrName : null;
    }

    public function newModel(Plugin $plugin, string $tableName): PluginTableRecord
    {
        return PluginTableRecord::forTable(
            $tableName,
            $this->jsonColumnsFor($plugin, $tableName),
            $this->usesTimestamps($plugin, $tableName),
        );
    }

    public function lookupLabel(Plugin $plugin, array $lookup, mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return $this->lookupQuery($plugin, $lookup)
            ?->where((string) ($lookup['key_column'] ?? 'id'), $value)
            ->value((string) ($lookup['label_column'] ?? 'name'));
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

        return $query
            ->orderBy($labelColumn)
            ->limit((int) ($lookup['limit'] ?? 500))
            ->pluck($labelColumn, $keyColumn)
            ->mapWithKeys(fn (mixed $label, mixed $value): array => [(string) $value => (string) $label])
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

        $existing = DB::table($targetTable)
            ->when(Schema::hasColumn($targetTable, 'extension_plugin_id'), fn ($query) => $query->where('extension_plugin_id', $plugin->id))
            ->pluck($targetColumn)
            ->map(fn (mixed $value): string => (string) $value)
            ->all();

        $defaults = is_array($prefill['defaults'] ?? null) ? $prefill['defaults'] : [];
        foreach ($sourceRows as $sourceRow) {
            $sourceValue = $sourceRow->{$sourceKey};
            if (in_array((string) $sourceValue, $existing, true)) {
                continue;
            }

            $this->newModel($plugin, $targetTable)->newQuery()->create(
                $this->prefillPayload($plugin, $targetTable, $targetColumn, $sourceValue, $sourceRow, $source, $defaults)
            );

            $existing[] = (string) $sourceValue;
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
            || (Schema::hasColumn($tableName, 'created_at') && Schema::hasColumn($tableName, 'updated_at'));
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
        $tableName = $this->tableNameFor($plugin, (string) ($lookup['table'] ?? ''), allowHostTable: true);
        if (! $tableName) {
            return null;
        }

        return DB::table($tableName)
            ->when(
                (bool) ($lookup['scope_plugin'] ?? false) && Schema::hasColumn($tableName, 'extension_plugin_id'),
                fn (QueryBuilder $query) => $query->where('extension_plugin_id', $plugin->id),
            )
            ->when(
                (bool) ($lookup['enabled_only'] ?? false) && Schema::hasColumn($tableName, 'enabled'),
                fn (QueryBuilder $query) => $query->where('enabled', true),
            );
    }

    private function columnsFor(Plugin $plugin, string $tableName)
    {
        $table = collect(data_get($plugin->schema_definition, 'tables', []))
            ->first(fn (array $table): bool => ($table['name'] ?? null) === $tableName);

        return collect($table['columns'] ?? []);
    }

    private function hasColumns(string $tableName, array $columns): bool
    {
        return $tableName !== ''
            && Schema::hasTable($tableName)
            && collect($columns)->every(fn (string $column): bool => $column !== '' && Schema::hasColumn($tableName, $column));
    }

    private function prefillSourceRows(string $sourceTable, string $sourceKey, array $source)
    {
        $userColumn = (string) ($source['user_column'] ?? 'user_id');
        $sourceColumns = [$sourceKey, ...(Schema::hasColumn($sourceTable, $userColumn) ? [$userColumn] : [])];
        $query = DB::table($sourceTable)->select(array_values(array_unique($sourceColumns)));

        if (($source['scope'] ?? null) === 'owned' && auth()->check() && Schema::hasColumn($sourceTable, $userColumn)) {
            $query->where($userColumn, auth()->id());
        }

        $orderColumn = (string) ($source['order_column'] ?? $sourceKey);
        if (Schema::hasColumn($sourceTable, $orderColumn)) {
            $query->orderBy($orderColumn);
        }

        return $query->get();
    }

    private function prefillPayload(Plugin $plugin, string $targetTable, string $targetColumn, mixed $sourceValue, object $sourceRow, array $source, array $defaults): array
    {
        $payload = [$targetColumn => $sourceValue];
        $userColumn = (string) ($source['user_column'] ?? 'user_id');

        if (Schema::hasColumn($targetTable, 'extension_plugin_id')) {
            $payload['extension_plugin_id'] = $plugin->id;
        }

        if (Schema::hasColumn($targetTable, 'user_id')) {
            $payload['user_id'] = $sourceRow->{$userColumn} ?? auth()->id();
        }

        foreach ($defaults as $key => $value) {
            data_set($payload, (string) $key, $value);
        }

        return $payload;
    }
}
