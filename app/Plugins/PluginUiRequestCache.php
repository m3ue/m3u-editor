<?php

namespace App\Plugins;

use Closure;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

#[Scoped]
class PluginUiRequestCache
{
    private ?Request $request = null;

    private array $values = [];

    public function remember(string $group, array $key, Closure $resolve): mixed
    {
        $this->resetForRequest();
        $key = hash('sha256', serialize($key));

        if (array_key_exists($key, $this->values[$group] ?? [])) {
            return $this->values[$group][$key];
        }

        $value = $resolve();
        $this->values[$group][$key] = $value;

        return $value;
    }

    public function forget(string ...$groups): void
    {
        $this->resetForRequest();
        foreach ($groups as $group) {
            unset($this->values[$group]);
        }
    }

    public function forgetData(): void
    {
        $this->forget('options', 'visibility', 'labels');
    }

    public function queryOptions(Builder $query, Closure $resolve, ?string $modelClass = null): array
    {
        return $this->remember('options', $this->queryKey($query, $modelClass), $resolve);
    }

    public function queryValue(Builder $query, Closure $resolve): mixed
    {
        return $this->remember('labels', $this->queryKey($query), $resolve);
    }

    private function queryKey(Builder $query, ?string $modelClass = null): array
    {
        return [
            spl_object_id($query->getConnection()),
            $modelClass,
            auth()->id(),
            $query->toSql(),
            $query->getBindings(),
        ];
    }

    private function resetForRequest(): void
    {
        if ($this->request !== request()) {
            $this->request = request();
            $this->values = [];
        }
    }

    public function columns(string $table, ?string $connection = null): array
    {
        $connection = DB::connection($connection);

        return $this->remember('columns', [
            spl_object_id($connection), $connection->getDatabaseName(), $connection->getTablePrefix(), $table,
        ], fn (): array => $connection->getSchemaBuilder()->getColumnListing($table));
    }

    public function hasTable(string $table, ?string $connection = null): bool
    {
        $connection = DB::connection($connection);

        return $this->remember('columns', [
            spl_object_id($connection), $connection->getDatabaseName(), $connection->getTablePrefix(), $table, 'exists',
        ], fn (): bool => $connection->getSchemaBuilder()->hasTable($table));
    }

    public function hasColumn(string $table, string $column, ?string $connection = null): bool
    {
        return in_array(strtolower($column), array_map(strtolower(...), $this->columns($table, $connection)), true);
    }
}
