<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ExecuteDatabaseQueryTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Execute a structured database query (SELECT, UPDATE, DELETE). Use this to dump or modify specific records accurately. Always use GetDatabaseSchemaTool first to know the table name and columns.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()
                ->description('The database table to query.')
                ->required(),
            'action' => $schema->string()
                ->description('The action to perform: "select", "update", or "delete".')
                ->required(),
            'where' => $schema->string()
                ->description('Optional JSON array of where conditions. Example: [{"column": "playlist_id", "operator": "=", "value": "5"}]'),
            'columns' => $schema->string()
                ->description('Optional JSON array of columns to return for a select query. Defaults to ["*"].'),
            'update_data' => $schema->string()
                ->description('JSON object containing key-value pairs for an update action.'),
            'limit' => $schema->integer()
                ->description('Optional limit for select queries. Defaults to 50.'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $table = (string) $request['table'];
        $action = strtolower((string) $request['action']);

        $whereRaw = $request['where'] ?? null;
        $where = $whereRaw ? json_decode((string) $whereRaw, true) : [];
        if (! is_array($where)) {
            $where = [];
        }

        $columnsRaw = $request['columns'] ?? null;
        $columns = $columnsRaw ? json_decode((string) $columnsRaw, true) : ['*'];
        if (! is_array($columns)) {
            $columns = ['*'];
        }

        $updateDataRaw = $request['update_data'] ?? null;
        $updateData = $updateDataRaw ? json_decode((string) $updateDataRaw, true) : [];
        if (! is_array($updateData)) {
            $updateData = [];
        }

        $limit = isset($request['limit']) ? (int) $request['limit'] : 50;

        if (! DbSchema::hasTable($table)) {
            return "Error: Table '{$table}' does not exist.";
        }

        $tableColumns = DbSchema::getColumnListing($table);
        $isAdmin = auth()->user()?->isAdmin() ?? false;

        $query = DB::table($table);

        // Security scoping
        if (! in_array('user_id', $tableColumns, true)) {
            if (! $isAdmin) {
                return "Error: You do not have permission to query table '{$table}' as it is not user-scoped.";
            }
        } else {
            $query->where('user_id', auth()->id());
        }

        // Apply where conditions
        if (is_array($where)) {
            foreach ($where as $condition) {
                if (! isset($condition['column'], $condition['operator'], $condition['value'])) {
                    continue;
                }

                $col = (string) $condition['column'];
                if (! in_array($col, $tableColumns, true)) {
                    return "Error: Column '{$col}' does not exist in table '{$table}'.";
                }

                $query->where($col, (string) $condition['operator'], $condition['value']);
            }
        }

        try {
            if ($action === 'select') {
                if (! is_array($columns) || empty($columns)) {
                    $columns = ['*'];
                }
                $results = $query->limit($limit)->get($columns);
                $json = json_encode($results, JSON_PRETTY_PRINT);

                if (strlen($json) > 15000) {
                    $json = substr($json, 0, 15000)."\n... [truncated] ...";
                }

                return $json;
            }

            if ($action === 'update') {
                if (empty($updateData) || ! is_array($updateData)) {
                    return 'Error: update_data is required and must be an object/array for update action.';
                }

                // Ensure non-admins don't try to change user_id
                if (! $isAdmin && isset($updateData['user_id'])) {
                    unset($updateData['user_id']);
                }

                $affected = $query->update($updateData);

                return "Successfully updated {$affected} rows.";
            }

            if ($action === 'delete') {
                $affected = $query->delete();

                return "Successfully deleted {$affected} rows.";
            }

            return "Error: Unsupported action '{$action}'. Use select, update, or delete.";
        } catch (\Throwable $e) {
            return 'Error executing query: '.$e->getMessage();
        }
    }
}
