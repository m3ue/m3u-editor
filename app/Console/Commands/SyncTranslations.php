<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Scans all Filament PHP files and Blade views for __('literal string') calls
 * and adds any missing entries to lang/en.json as identity mappings.
 *
 * Also detects and fixes escaped-quote bugs inside __() single-quoted strings:
 *   __('has \"quotes\"')  →  __('has "quotes"')
 * In PHP single-quoted strings, \" is NOT an escape sequence — it is a literal
 * backslash + quote, which never matches the JSON key "has "quotes"".
 *
 * Run this after app:extract-translations:
 *   php artisan app:sync-translations
 *   php artisan app:sync-translations --dry-run
 *   php artisan app:sync-translations --fix-escapes   (also fix \" in __() calls)
 */
class SyncTranslations extends Command
{
    protected $signature = 'app:sync-translations
        {--dry-run : Preview changes without writing files}
        {--fix-escapes : Fix escaped quotes (\\") inside __() single-quoted strings}';

    protected $description = 'Sync __() string literals into lang/en.json as identity entries, and optionally fix escaped-quote bugs';

    private array $newKeys = [];

    private array $escapeFixed = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fixEscapes = (bool) $this->option('fix-escapes');

        $phpDirs = [
            app_path('Filament'),
            app_path('Enums'),
        ];
        $bladeDirs = [
            resource_path('views/filament'),
        ];

        foreach ($phpDirs as $dir) {
            if (is_dir($dir)) {
                $this->scanDirectory($dir, 'php', $fixEscapes, $dryRun);
            }
        }

        foreach ($bladeDirs as $dir) {
            if (is_dir($dir)) {
                $this->scanDirectory($dir, 'blade.php', $fixEscapes, $dryRun);
            }
        }

        // Load existing en.json
        $enJsonPath = lang_path('en.json');
        $existing = [];
        if (file_exists($enJsonPath)) {
            $existing = json_decode(file_get_contents($enJsonPath), true) ?? [];
        }

        $before = count($existing);

        // New keys get identity values; existing keys are preserved unchanged.
        // array_merge() would renumber purely-numeric-string keys (e.g. "9", "10")
        // instead of treating them as translation keys, corrupting their values.
        $merged = $existing + $this->newKeys;
        ksort($merged);
        $after = count($merged);
        $addedCount = $after - $before;

        $this->newLine();
        $this->line('Found <comment>'.count($this->newKeys).'</comment> unique __() strings in source files.');
        $this->info("  {$addedCount} new keys to add to en.json");
        $this->line('  '.count($existing).' existing keys preserved');

        if ($fixEscapes && ! empty($this->escapeFixed)) {
            $this->newLine();
            $this->warn('Escaped-quote fixes applied in '.count($this->escapeFixed).' file(s):');
            foreach ($this->escapeFixed as $path) {
                $this->line("  {$path}");
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->line('<comment>Dry run — no files written.</comment>');
            if ($addedCount > 0) {
                $this->line('New keys that would be added:');
                $newOnes = array_diff_key($this->newKeys, $existing);
                foreach (array_keys($newOnes) as $k) {
                    $this->line("  + {$k}");
                }
            }
        } else {
            file_put_contents(
                $enJsonPath,
                json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
            );
            $this->newLine();
            $this->info("en.json updated: {$after} total keys (+{$addedCount} new).");
        }

        return self::SUCCESS;
    }

    private function scanDirectory(string $dir, string $extension, bool $fixEscapes, bool $dryRun): void
    {
        $files = File::allFiles($dir);

        foreach ($files as $file) {
            $name = $file->getFilename();

            // Match both .php and .blade.php
            if ($extension === 'blade.php') {
                if (! str_ends_with($name, '.blade.php')) {
                    continue;
                }
            } else {
                if ($file->getExtension() !== 'php' || str_ends_with($name, '.blade.php')) {
                    continue;
                }
            }

            $path = $file->getRealPath();
            $content = file_get_contents($path);
            $modified = $content;

            // Fix escaped quotes inside __() single-quoted strings if requested
            if ($fixEscapes) {
                $fixed = preg_replace_callback(
                    "/__\('((?:[^'\\\\]|\\\\.)*)'\)/",
                    function (array $m) {
                        $inner = $m[1];
                        if (str_contains($inner, '\\"')) {
                            return "__('".str_replace('\\"', '"', $inner)."')";
                        }

                        return $m[0];
                    },
                    $modified
                );

                if ($fixed !== $modified) {
                    $modified = $fixed;
                    $rel = ltrim(str_replace(base_path(), '', $path), '/');
                    $this->escapeFixed[] = $rel;
                    if (! $dryRun) {
                        file_put_contents($path, $modified);
                    }
                }
            }

            // Collect __('literal') keys from the (possibly fixed) content
            preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*[,)]/", $modified, $matches);

            foreach ($matches[1] as $raw) {
                $value = stripslashes($raw);
                // Skip: too short, numeric, Filament vendor keys, dot-notation PHP-array keys
                if (
                    strlen($value) <= 1
                    || is_numeric($value)
                    || str_starts_with($value, 'filament-')
                    || (str_contains($value, '.') && ! str_contains($value, ' '))
                ) {
                    continue;
                }

                $this->newKeys[$value] = $value;
            }
        }
    }
}
