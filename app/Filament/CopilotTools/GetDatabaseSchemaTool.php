<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Schema as DbSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetDatabaseSchemaTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Get the database schema (list of tables and their columns). Useful before writing structured SQL queries. If you are not an admin, you will only see user-scoped tables.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()
                ->description(__('Optional. The name of a specific table to get the schema for. If omitted, returns all accessible tables.')),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $targetTable = $request['table'] ?? null;
        $isAdmin = auth()->user()?->isAdmin() ?? false;

        $allTables = DbSchema::getTables();
        $tables = array_column($allTables, 'name');

        $schemaText = '';

        if ($targetTable) {
            if (! in_array($targetTable, $tables, true)) {
                return "Error: Table '{$targetTable}' not found in the database.";
            }
            $columns = DbSchema::getColumnListing($targetTable);

            if (! $isAdmin && ! in_array('user_id', $columns, true)) {
                return "Error: You do not have permission to access table '{$targetTable}' because it is not scoped to a user account.";
            }

            $schemaText .= "Table: {$targetTable}\nColumns: ".implode(', ', $columns)."\n";
        } else {
            foreach ($tables as $table) {
                $columns = DbSchema::getColumnListing($table);

                if (! $isAdmin && ! in_array('user_id', $columns, true)) {
                    continue;
                }

                $schemaText .= "Table: {$table} (".implode(', ', $columns).")\n";
            }

            if (empty($schemaText)) {
                $schemaText = 'No accessible tables found.';
            }
        }

        return $schemaText;
    }
}
