<?php

namespace App\Plugins\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class PluginManifest
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $version,
        public readonly ?string $apiVersion,
        public readonly ?string $description,
        public readonly string $entrypoint,
        public readonly string $className,
        public readonly array $capabilities,
        public readonly array $hooks,
        public readonly array $permissions,
        public readonly array $schema,
        public readonly array $settings,
        public readonly array $actions,
        public readonly array $dataOwnership,
        public readonly ?string $repository,
        public readonly string $path,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $manifest, string $path): self
    {
        $pluginId = (string) ($manifest['id'] ?? basename($path));
        $schema = self::normalizeSchema(
            Arr::get($manifest, 'schema', []),
            $pluginId,
        );

        return new self(
            id: $pluginId,
            name: (string) ($manifest['name'] ?? ($manifest['id'] ?? basename($path))),
            version: isset($manifest['version']) ? (string) $manifest['version'] : null,
            apiVersion: isset($manifest['api_version']) ? (string) $manifest['api_version'] : null,
            description: isset($manifest['description']) ? (string) $manifest['description'] : null,
            entrypoint: (string) ($manifest['entrypoint'] ?? 'Plugin.php'),
            className: (string) ($manifest['class'] ?? ''),
            capabilities: array_values($manifest['capabilities'] ?? []),
            hooks: array_values($manifest['hooks'] ?? []),
            permissions: self::normalizeStringList($manifest['permissions'] ?? [], 'permissions'),
            schema: $schema,
            settings: array_values($manifest['settings'] ?? []),
            actions: array_values($manifest['actions'] ?? []),
            dataOwnership: self::normalizeDataOwnership(
                Arr::get($manifest, 'data_ownership', []),
                $pluginId,
                $schema,
            ),
            repository: self::normalizeRepository($manifest['repository'] ?? null),
            path: $path,
            raw: $manifest,
        );
    }

    public function entrypointPath(): string
    {
        return rtrim($this->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$this->entrypoint;
    }

    private static function normalizeDataOwnership(mixed $dataOwnership, string $pluginId, array $schema): array
    {
        if ($dataOwnership === [] || $dataOwnership === null) {
            return self::defaultDataOwnership($pluginId, $schema);
        }

        if (! is_array($dataOwnership)) {
            throw new RuntimeException('Manifest field [data_ownership] must be an object.');
        }

        $cleanupPolicy = (string) ($dataOwnership['default_cleanup_policy'] ?? 'preserve');
        if (! in_array($cleanupPolicy, ['preserve', 'purge'], true)) {
            throw new RuntimeException('Manifest field [data_ownership.default_cleanup_policy] must be either [preserve] or [purge].');
        }

        $tables = self::normalizeStringList($dataOwnership['tables'] ?? [], 'data_ownership.tables');

        return [
            'plugin_id' => $pluginId,
            'table_prefix' => 'plugin_'.Str::of($pluginId)->replace('-', '_')->lower()->value().'_',
            'tables' => array_values(array_unique([
                ...$tables,
                ...collect($schema['tables'] ?? [])->pluck('name')->filter()->all(),
            ])),
            'directories' => self::normalizeStoragePathList($dataOwnership['directories'] ?? [], 'data_ownership.directories'),
            'files' => self::normalizeStoragePathList($dataOwnership['files'] ?? [], 'data_ownership.files'),
            'default_cleanup_policy' => $cleanupPolicy,
        ];
    }

    private static function normalizeSchema(mixed $schema, string $pluginId): array
    {
        if ($schema === [] || $schema === null) {
            return ['tables' => []];
        }

        if (! is_array($schema)) {
            throw new RuntimeException('Manifest field [schema] must be an object.');
        }

        $tables = collect($schema['tables'] ?? [])
            ->map(function (mixed $table) use ($pluginId): array {
                if (! is_array($table)) {
                    throw new RuntimeException('Manifest field [schema.tables] must only contain objects.');
                }

                return [
                    'name' => trim((string) ($table['name'] ?? '')),
                    'columns' => array_values($table['columns'] ?? []),
                    'indexes' => array_values($table['indexes'] ?? []),
                    'plugin_id' => $pluginId,
                ];
            })
            ->all();

        return [
            'tables' => $tables,
        ];
    }

    private static function normalizeStringList(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw new RuntimeException("Manifest field [{$field}] must be a list.");
        }

        return array_values(array_unique(array_filter(array_map(function (mixed $item) use ($field): string {
            if (! is_string($item)) {
                throw new RuntimeException("Manifest field [{$field}] must only contain strings.");
            }

            return trim($item);
        }, $value))));
    }

    private static function normalizeStoragePathList(mixed $value, string $field): array
    {
        return array_values(array_unique(array_map(function (string $path): string {
            return trim(str_replace('\\', '/', $path), '/');
        }, self::normalizeStringList($value, $field))));
    }

    /**
     * Normalize a repository value to "owner/repo" shorthand.
     */
    public static function normalizeRepository(mixed $repository): ?string
    {
        if ($repository === null || $repository === '') {
            return null;
        }

        if (! is_string($repository)) {
            throw new RuntimeException('Manifest field [repository] must be a string.');
        }

        $repository = trim($repository);

        if (preg_match('#^https?://github\.com/([^/]+/[^/]+?)(?:\.git)?/?$#i', $repository, $matches)) {
            return $matches[1];
        }

        if (preg_match('#^[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+$#', $repository)) {
            return $repository;
        }

        throw new RuntimeException('Manifest field [repository] must be "owner/repo" or a GitHub URL (e.g. https://github.com/owner/repo).');
    }

    private static function defaultDataOwnership(string $pluginId, array $schema): array
    {
        return [
            'plugin_id' => $pluginId,
            'table_prefix' => 'plugin_'.Str::of($pluginId)->replace('-', '_')->lower()->value().'_',
            'tables' => collect($schema['tables'] ?? [])->pluck('name')->filter()->values()->all(),
            'directories' => [],
            'files' => [],
            'default_cleanup_policy' => 'preserve',
        ];
    }
}
