<?php

namespace App\Plugins;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PluginSchemaManager
{
    public function apply(array $schema): void
    {
        foreach ($schema['tables'] ?? [] as $table) {
            $tableName = (string) ($table['name'] ?? '');
            if ($tableName === '') {
                continue;
            }

            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $blueprint) use ($table): void {
                    $this->applyColumns($blueprint, $table['columns'] ?? []);
                    $this->applyIndexes($blueprint, $table['indexes'] ?? []);
                });

                continue;
            }

            $existingColumns = Schema::getColumnListing($tableName);
            $missingColumns = collect($table['columns'] ?? [])
                ->filter(function (array $column) use ($existingColumns): bool {
                    $type = $column['type'] ?? null;
                    if ($type === 'timestamps') {
                        return ! in_array('created_at', $existingColumns, true) || ! in_array('updated_at', $existingColumns, true);
                    }

                    $name = $column['name'] ?? null;

                    return is_string($name) && ! in_array($name, $existingColumns, true);
                })
                ->values()
                ->all();

            if ($missingColumns === []) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $blueprint) use ($missingColumns): void {
                $this->applyColumns($blueprint, $missingColumns);
            });
        }
    }

    public function purge(array $schema): void
    {
        foreach (array_reverse($schema['tables'] ?? []) as $table) {
            $tableName = (string) ($table['name'] ?? '');

            if ($tableName !== '') {
                Schema::dropIfExists($tableName);
            }
        }
    }

    public function diagnostics(string $pluginId, array $schema): array
    {
        $issues = [];

        foreach ($schema['tables'] ?? [] as $table) {
            $tableName = (string) ($table['name'] ?? '');
            if ($tableName === '') {
                continue;
            }

            if (! Schema::hasTable($tableName)) {
                $issues[] = [
                    'plugin_id' => $pluginId,
                    'level' => 'error',
                    'code' => 'schema_table_missing',
                    'message' => "Declared schema table [{$tableName}] is missing.",
                ];

                continue;
            }

            $existingColumns = Schema::getColumnListing($tableName);
            foreach ($table['columns'] ?? [] as $column) {
                $type = $column['type'] ?? null;
                if ($type === 'timestamps') {
                    foreach (['created_at', 'updated_at'] as $timestampColumn) {
                        if (! in_array($timestampColumn, $existingColumns, true)) {
                            $issues[] = [
                                'plugin_id' => $pluginId,
                                'level' => 'warning',
                                'code' => 'schema_column_missing',
                                'message' => "Declared schema column [{$tableName}.{$timestampColumn}] is missing.",
                            ];
                        }
                    }

                    continue;
                }

                $columnName = $column['name'] ?? null;
                if (is_string($columnName) && ! in_array($columnName, $existingColumns, true)) {
                    $issues[] = [
                        'plugin_id' => $pluginId,
                        'level' => 'warning',
                        'code' => 'schema_column_missing',
                        'message' => "Declared schema column [{$tableName}.{$columnName}] is missing.",
                    ];
                }
            }
        }

        return $issues;
    }

    private function applyColumns(Blueprint $blueprint, array $columns): void
    {
        foreach ($columns as $column) {
            $type = (string) ($column['type'] ?? '');
            $name = (string) ($column['name'] ?? '');

            if ($type === 'timestamps') {
                $blueprint->timestamps();

                continue;
            }

            if ($name === '') {
                throw new RuntimeException('Schema column definitions require [name].');
            }

            $definition = match ($type) {
                'id' => $blueprint->id($name),
                'foreignId' => $blueprint->foreignId($name),
                'string' => $blueprint->string($name, (int) ($column['length'] ?? 255)),
                'text' => $blueprint->text($name),
                'boolean' => $blueprint->boolean($name),
                'integer' => $blueprint->integer($name),
                'bigInteger' => $blueprint->bigInteger($name),
                'decimal' => $blueprint->decimal($name, (int) ($column['precision'] ?? 8), (int) ($column['scale'] ?? 2)),
                'json' => $blueprint->json($name),
                'timestamp' => $blueprint->timestamp($name),
                default => throw new RuntimeException("Unsupported schema column type [{$type}]."),
            };

            if ((bool) ($column['nullable'] ?? false)) {
                $definition->nullable();
            }

            if (array_key_exists('default', $column)) {
                $definition->default($column['default']);
            }

            if ($type === 'foreignId' && filled($column['references'] ?? null)) {
                $constraint = $definition->constrained((string) $column['references']);
                match ($column['on_delete'] ?? null) {
                    'cascade' => $constraint->cascadeOnDelete(),
                    'null' => $constraint->nullOnDelete(),
                    default => null,
                };
            }
        }
    }

    private function applyIndexes(Blueprint $blueprint, array $indexes): void
    {
        foreach ($indexes as $index) {
            $columns = Arr::wrap($index['columns'] ?? []);
            if ($columns === []) {
                continue;
            }

            match ($index['type'] ?? 'index') {
                'unique' => $blueprint->unique($columns, $index['name'] ?? null),
                default => $blueprint->index($columns, $index['name'] ?? null),
            };
        }
    }
}
