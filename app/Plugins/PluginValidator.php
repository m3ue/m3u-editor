<?php

namespace App\Plugins;

use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Support\PluginManifest;
use App\Plugins\Support\PluginValidationResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class PluginValidator
{
    public function __construct(
        private readonly PluginManifestLoader $loader,
        private readonly PluginIntegrityService $integrityService,
    ) {}

    public function validatePath(string $pluginPath): PluginValidationResult
    {
        $errors = [];
        $manifest = null;
        $manifestData = [];
        $pluginId = basename($pluginPath);

        try {
            $manifest = $this->loader->load($pluginPath);
            $manifestData = $manifest->raw;
            $pluginId = $manifest->id;
        } catch (Throwable $exception) {
            return new PluginValidationResult(false, [$exception->getMessage()], null, $manifestData, $pluginId, []);
        }

        $hashes = $this->integrityService->hashesForPlugin($pluginPath, $manifest->entrypoint);

        foreach (['id', 'name', 'entrypoint', 'class'] as $key) {
            if (blank($manifestData[$key] ?? null)) {
                $errors[] = "Missing required manifest field [{$key}]";
            }
        }

        if (($manifestData['api_version'] ?? null) !== config('plugins.api_version')) {
            $errors[] = 'Plugin api_version does not match host plugin API version.';
        }

        if (isset($manifestData['repository']) && $manifestData['repository'] !== '') {
            if (! preg_match('#^[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+$#', (string) $manifest->repository)) {
                $errors[] = 'Manifest field [repository] must be in \"owner/repo\" format (GitHub URL is normalized during parsing).';
            }
        }

        $knownCapabilities = array_keys(config('plugins.capabilities', []));
        foreach ($manifest->capabilities as $capability) {
            if (! in_array($capability, $knownCapabilities, true)) {
                $errors[] = "Unknown capability [{$capability}]";
            }
        }

        $knownHooks = array_keys(config('plugins.hooks', []));
        foreach ($manifest->hooks as $hook) {
            if (! is_string($hook) || ! in_array($hook, $knownHooks, true)) {
                $errors[] = "Unknown hook [{$hook}]";
            }
        }

        $knownPermissions = array_keys(config('plugins.permissions', []));
        foreach ($manifest->permissions as $permission) {
            if (! in_array($permission, $knownPermissions, true)) {
                $errors[] = "Unknown permission [{$permission}]";
            }
        }

        if (($manifest->actions !== [] || $manifest->hooks !== [] || in_array('scheduled', $manifest->capabilities, true))
            && ! in_array('queue_jobs', $manifest->permissions, true)) {
            $errors[] = 'Plugins that queue actions, hooks, or schedules must declare [queue_jobs].';
        }

        if ($manifest->hooks !== [] && ! in_array('hook_subscriptions', $manifest->permissions, true)) {
            $errors[] = 'Plugins declaring hooks must declare [hook_subscriptions].';
        }

        if (in_array('scheduled', $manifest->capabilities, true) && ! in_array('scheduled_runs', $manifest->permissions, true)) {
            $errors[] = 'Plugins using the [scheduled] capability must declare [scheduled_runs].';
        }

        if (($manifest->schema['tables'] ?? []) !== [] && ! in_array('schema_manage', $manifest->permissions, true)) {
            $errors[] = 'Plugins declaring [schema.tables] must declare [schema_manage].';
        }

        if ((($manifest->dataOwnership['directories'] ?? []) !== [] || ($manifest->dataOwnership['files'] ?? []) !== [])
            && ! in_array('filesystem_write', $manifest->permissions, true)) {
            $errors[] = 'Plugins declaring owned files or directories must declare [filesystem_write].';
        }

        $fieldTypes = config('plugins.field_types', []);
        foreach ($manifest->settings as $field) {
            $errors = [...$errors, ...$this->validateFieldDefinition($field, $fieldTypes, 'settings')];
        }

        $actionIds = [];
        foreach ($manifest->actions as $action) {
            $actionId = $action['id'] ?? null;
            if (blank($actionId)) {
                $errors[] = 'Action missing required field [id]';

                continue;
            }

            if (in_array($actionId, $actionIds, true)) {
                $errors[] = "Duplicate action id [{$actionId}]";
            }

            $actionIds[] = $actionId;

            foreach ($action['fields'] ?? [] as $field) {
                $errors = [...$errors, ...$this->validateFieldDefinition($field, $fieldTypes, "actions.{$actionId}")];
            }
        }

        $errors = [...$errors, ...$this->validateDataOwnership($manifest)];
        $errors = [...$errors, ...$this->validateSchema($manifest)];

        if (! file_exists($manifest->entrypointPath())) {
            $errors[] = "Missing entrypoint file [{$manifest->entrypoint}]";
        } else {
            try {
                $entrypointAnalysis = $this->inspectEntrypoint($manifest->entrypointPath());
            } catch (Throwable $exception) {
                $errors[] = "Entrypoint failed static inspection: {$exception->getMessage()}";
                $entrypointAnalysis = null;
            }

            if ($entrypointAnalysis) {
                $declaredClass = $entrypointAnalysis['class_name'];
                $declaredInterfaces = $entrypointAnalysis['interfaces'];

                if ($declaredClass !== ltrim($manifest->className, '\\')) {
                    $errors[] = "Entrypoint declares [{$declaredClass}] but manifest expects [{$manifest->className}]";
                }

                foreach ($this->requiredInterfacesForManifest($manifest) as $requiredInterface) {
                    if (! $this->satisfiesInterfaceRequirement($requiredInterface, $declaredInterfaces)) {
                        $errors[] = "Plugin class [{$manifest->className}] must implement [{$requiredInterface}]";
                    }
                }
            }
        }

        return new PluginValidationResult($errors === [], $errors, $manifest, $manifestData, $pluginId, $hashes);
    }

    /**
     * Inspect a plugin entrypoint without executing it.
     *
     * This keeps reviewed-install validation on the safe side of the trust
     * boundary: the file is tokenized and matched against the manifest, but
     * top-level PHP never runs until the plugin is trusted and invoked.
     *
     * @return array{class_name: string, interfaces: array<int, string>}
     */
    private function inspectEntrypoint(string $entrypointPath): array
    {
        $source = file_get_contents($entrypointPath);
        if ($source === false) {
            throw new \RuntimeException("Unable to read entrypoint file [{$entrypointPath}].");
        }

        $tokens = token_get_all($source);
        $this->assertSafeTopLevelStructure($tokens);
        $namespace = '';
        $imports = [];
        $className = null;
        $interfaces = [];
        $braceDepth = 0;

        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            if (is_string($token)) {
                if ($token === '{') {
                    $braceDepth++;
                } elseif ($token === '}') {
                    $braceDepth = max(0, $braceDepth - 1);
                }

                continue;
            }

            [$id] = $token;

            if ($id === T_NAMESPACE && $braceDepth === 0) {
                [$namespace, $index] = $this->consumeNameStatement($tokens, $index + 1);

                continue;
            }

            if ($id === T_USE && $braceDepth === 0 && $className === null) {
                [$imports, $index] = $this->consumeUseStatements($tokens, $index + 1, $imports);

                continue;
            }

            if ($id !== T_CLASS || $braceDepth !== 0 || $this->isAnonymousClass($tokens, $index)) {
                continue;
            }

            $className = $this->consumeNextIdentifier($tokens, $index + 1);
            if ($className === null) {
                throw new \RuntimeException('Entrypoint is missing a concrete plugin class declaration.');
            }

            [$interfaces, $index] = $this->consumeClassInterfaces($tokens, $index + 1, $namespace, $imports);
            break;
        }

        if ($className === null) {
            throw new \RuntimeException('Entrypoint does not declare a plugin class.');
        }

        return [
            'class_name' => $this->resolveImportedName($className, $namespace, $imports),
            'interfaces' => array_values(array_unique($interfaces)),
        ];
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    private function assertSafeTopLevelStructure(array $tokens): void
    {
        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            if (is_string($token)) {
                continue;
            }

            [$id, $text] = $token;

            if (in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (in_array($id, [T_NAMESPACE, T_USE, T_DECLARE], true)) {
                $index = $this->skipStatement($tokens, $index + 1);

                continue;
            }

            if (in_array($id, [T_FINAL, T_ABSTRACT], true)) {
                continue;
            }

            if ($id === T_CLASS) {
                return;
            }

            throw new \RuntimeException(
                sprintf(
                    'Entrypoint contains top-level executable code [%s]. Reviewed plugins must keep executable logic inside the plugin class.',
                    trim($text) !== '' ? trim($text) : token_name($id),
                ),
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function requiredInterfacesForManifest(PluginManifest $manifest): array
    {
        $interfaces = [PluginInterface::class];

        foreach ($manifest->capabilities as $capability) {
            $requiredInterface = config("plugins.capabilities.{$capability}.interface");
            if (is_string($requiredInterface) && $requiredInterface !== '') {
                $interfaces[] = ltrim($requiredInterface, '\\');
            }
        }

        if ($manifest->hooks !== []) {
            $interfaces[] = HookablePluginInterface::class;
        }

        return array_values(array_unique(array_map(
            static fn (string $interface): string => ltrim($interface, '\\'),
            $interfaces,
        )));
    }

    /**
     * @param  array<int, string>  $declaredInterfaces
     */
    private function satisfiesInterfaceRequirement(string $requiredInterface, array $declaredInterfaces): bool
    {
        foreach ($declaredInterfaces as $declaredInterface) {
            if ($declaredInterface === $requiredInterface || is_a($declaredInterface, $requiredInterface, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $tokens
     * @return array{0: string, 1: int}
     */
    private function consumeNameStatement(array $tokens, int $index): array
    {
        $parts = [];

        for (; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            if (is_string($token)) {
                if (in_array($token, [';', '{'], true)) {
                    break;
                }

                continue;
            }

            if (in_array($token[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $parts[] = $token[1];
            }
        }

        return [ltrim(implode('', $parts), '\\'), $index];
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    private function skipStatement(array $tokens, int $index): int
    {
        $depth = 0;

        for (; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            if (! is_string($token)) {
                continue;
            }

            if ($token === '(') {
                $depth++;

                continue;
            }

            if ($token === ')') {
                $depth = max(0, $depth - 1);

                continue;
            }

            if (($token === ';' || $token === '{') && $depth === 0) {
                return $index;
            }
        }

        return $index;
    }

    /**
     * @param  array<int, mixed>  $tokens
     * @param  array<string, string>  $imports
     * @return array{0: array<string, string>, 1: int}
     */
    private function consumeUseStatements(array $tokens, int $index, array $imports): array
    {
        $statement = '';

        for (; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            if (is_string($token)) {
                if ($token === ';') {
                    break;
                }

                $statement .= $token;

                continue;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $statement .= $token[1];
        }

        if (str_contains($statement, '{')) {
            throw new \RuntimeException('Grouped use statements are not supported in plugin entrypoints.');
        }

        foreach (array_filter(array_map('trim', explode(',', $statement))) as $import) {
            if (preg_match('/^(function|const)\s+/i', $import)) {
                continue;
            }

            $segments = preg_split('/\s+as\s+/i', $import);
            $fqcn = ltrim(trim((string) $segments[0]), '\\');
            $alias = trim((string) ($segments[1] ?? Str::afterLast($fqcn, '\\')));

            if ($fqcn !== '' && $alias !== '') {
                $imports[$alias] = $fqcn;
            }
        }

        return [$imports, $index];
    }

    /**
     * @param  array<int, mixed>  $tokens
     * @param  array<string, string>  $imports
     * @return array{0: array<int, string>, 1: int}
     */
    private function consumeClassInterfaces(array $tokens, int $index, string $namespace, array $imports): array
    {
        $interfaces = [];
        $collecting = false;
        $buffer = '';

        for (; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            if (is_string($token)) {
                if ($token === '{') {
                    break;
                }

                if ($collecting) {
                    $buffer .= $token;
                }

                continue;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                if ($collecting) {
                    $buffer .= ' ';
                }

                continue;
            }

            if ($token[0] === T_IMPLEMENTS) {
                $collecting = true;

                continue;
            }

            if (! $collecting) {
                continue;
            }

            $buffer .= $token[1];
        }

        foreach (array_filter(array_map('trim', explode(',', $buffer))) as $interface) {
            $resolved = $this->resolveImportedName($interface, $namespace, $imports);
            if ($resolved !== '') {
                $interfaces[] = $resolved;
            }
        }

        return [$interfaces, $index];
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    private function consumeNextIdentifier(array $tokens, int $index): ?string
    {
        for (; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $tokens
     */
    private function isAnonymousClass(array $tokens, int $index): bool
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];

            if (is_string($token)) {
                if (trim($token) === '') {
                    continue;
                }

                return false;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token[0] === T_NEW;
        }

        return false;
    }

    /**
     * @param  array<string, string>  $imports
     */
    private function resolveImportedName(string $name, string $namespace, array $imports): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $segments = explode('\\', $name);
        $head = $segments[0];

        if (isset($imports[$head])) {
            $tail = array_slice($segments, 1);

            return $imports[$head].($tail === [] ? '' : '\\'.implode('\\', $tail));
        }

        return $namespace !== '' ? $namespace.'\\'.$name : $name;
    }

    private function validateFieldDefinition(array $field, array $fieldTypes, string $group): array
    {
        $errors = [];
        $fieldId = $field['id'] ?? null;

        if (blank($fieldId)) {
            return ["{$group} field is missing [id]"];
        }

        $type = $field['type'] ?? 'text';
        if (! in_array($type, $fieldTypes, true)) {
            $errors[] = "{$group}.{$fieldId} uses unsupported type [{$type}]";
        }

        if (in_array($type, ['select', 'model_select'], true) && blank($field['label'] ?? null)) {
            $errors[] = "{$group}.{$fieldId} should define a human-friendly [label]";
        }

        if ($type === 'select' && empty($field['options'])) {
            $errors[] = "{$group}.{$fieldId} select fields require [options]";
        }

        if ($type === 'model_select' && blank($field['model'] ?? null)) {
            $errors[] = "{$group}.{$fieldId} model_select fields require [model]";
        }

        return $errors;
    }

    private function validateDataOwnership(PluginManifest $manifest): array
    {
        $errors = [];
        $ownership = $manifest->dataOwnership;

        if (! in_array($ownership['default_cleanup_policy'] ?? null, config('plugins.cleanup_modes', []), true)) {
            $errors[] = 'data_ownership.default_cleanup_policy must be one of the supported cleanup modes.';
        }

        $tablePrefix = (string) ($ownership['table_prefix'] ?? '');
        foreach ($ownership['tables'] ?? [] as $table) {
            if (! Str::startsWith($table, $tablePrefix)) {
                $errors[] = "Declared table [{$table}] must start with [{$tablePrefix}] so uninstall can safely purge plugin-owned data.";
            }
        }

        $allowedRoots = collect(config('plugins.owned_storage_roots', []))
            ->map(fn (string $root) => trim($root, '/'))
            ->filter()
            ->all();

        foreach (['directories', 'files'] as $group) {
            foreach ($ownership[$group] ?? [] as $path) {
                if (Str::startsWith($path, '/') || Str::contains($path, ['..', '\\'])) {
                    $errors[] = "Declared {$group} path [{$path}] must stay inside approved storage roots.";

                    continue;
                }

                if (! collect($allowedRoots)->contains(fn (string $root) => Str::startsWith($path, $root.'/') || $path === $root)) {
                    $errors[] = "Declared {$group} path [{$path}] must start with one of: ".implode(', ', $allowedRoots);

                    continue;
                }

                if (! Str::contains($path, '/'.$manifest->id) && ! Str::contains($path, '/'.Str::of($manifest->id)->replace('-', '_')->value())) {
                    $errors[] = "Declared {$group} path [{$path}] must include the plugin id so cleanup stays namespaced.";
                }
            }
        }

        return $errors;
    }

    private function validateSchema(PluginManifest $manifest): array
    {
        $errors = [];
        $tablePrefix = (string) data_get($manifest->dataOwnership, 'table_prefix', '');
        $supportedColumnTypes = config('plugins.schema_column_types', []);
        $supportedIndexTypes = config('plugins.schema_index_types', []);

        foreach ($manifest->schema['tables'] ?? [] as $table) {
            $tableName = trim((string) ($table['name'] ?? ''));

            if ($tableName === '') {
                $errors[] = 'Schema tables require [name].';

                continue;
            }

            if (! Str::startsWith($tableName, $tablePrefix)) {
                $errors[] = "Declared schema table [{$tableName}] must start with [{$tablePrefix}].";
            }

            if (($table['columns'] ?? []) === []) {
                $errors[] = "Declared schema table [{$tableName}] must define at least one column.";
            }

            foreach ($table['columns'] ?? [] as $index => $column) {
                $columnPath = "schema.tables.{$tableName}.columns.{$index}";
                $type = $column['type'] ?? null;

                if (! is_string($type) || ! in_array($type, $supportedColumnTypes, true)) {
                    $errors[] = "{$columnPath} uses unsupported type [{$type}]";

                    continue;
                }

                if ($type !== 'timestamps' && blank($column['name'] ?? null)) {
                    $errors[] = "{$columnPath} requires [name]";
                }

                if ($type === 'foreignId' && blank($column['references'] ?? null)) {
                    $errors[] = "{$columnPath} foreignId columns require [references]";
                }
            }

            foreach ($table['indexes'] ?? [] as $index => $definition) {
                $indexPath = "schema.tables.{$tableName}.indexes.{$index}";
                $indexType = $definition['type'] ?? 'index';

                if (! in_array($indexType, $supportedIndexTypes, true)) {
                    $errors[] = "{$indexPath} uses unsupported type [{$indexType}]";
                }

                if (Arr::wrap($definition['columns'] ?? []) === []) {
                    $errors[] = "{$indexPath} requires [columns]";
                }
            }
        }

        return $errors;
    }
}
