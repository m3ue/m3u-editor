<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifestPath = $root.'/plugin.json';

if (! is_file($manifestPath)) {
    fwrite(STDERR, "Missing plugin.json\n");
    exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (! is_array($manifest)) {
    fwrite(STDERR, "plugin.json must contain valid JSON\n");
    exit(1);
}

$requiredFields = ['id', 'name', 'entrypoint', 'class'];
foreach ($requiredFields as $field) {
    if (! isset($manifest[$field]) || ! is_string($manifest[$field]) || trim($manifest[$field]) === '') {
        fwrite(STDERR, "Missing required manifest field: {$field}\n");
        exit(1);
    }
}

if (($manifest['id'] ?? null) !== 'tv-logos') {
    fwrite(STDERR, 'Expected plugin id tv-logos, found '.($manifest['id'] ?? 'unknown')."\n");
    exit(1);
}

$entrypointPath = $root.'/'.ltrim((string) $manifest['entrypoint'], '/');
if (! is_file($entrypointPath)) {
    fwrite(STDERR, "Missing entrypoint file: {$manifest['entrypoint']}\n");
    exit(1);
}

echo "Validated tv-logos plugin files.\n";
