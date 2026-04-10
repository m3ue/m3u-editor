<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Scans Filament PHP files for hardcoded string literals and:
 *  1. Wraps them with __('English string') — using Laravel JSON translations.
 *  2. Writes lang/en.json as the source-of-truth (key = English string).
 *  3. Converts static $navigationLabel / $modelLabel / $pluralModelLabel
 *     to method overrides (required because static props can't call functions).
 *
 * Uses the JSON translation approach: key = English value.
 * Translations live in lang/de.json, lang/fr.json, lang/es.json.
 * For EN the key is returned as-is (no lang/en.json needed).
 *
 * Navigation group names (already in lang/en/navigation.php) are left alone
 * since they already use __('navigation.groups.*') PHP-array keys.
 *
 * Run before a release to capture newly added strings:
 *   php artisan app:extract-translations
 *   php artisan app:extract-translations --dry-run
 *   php artisan app:extract-translations --path=app/Filament/Resources
 */
class ExtractTranslations extends Command
{
    protected $signature = 'app:extract-translations
        {--dry-run : Preview changes without writing files}
        {--path=app/Filament : Directory to scan (relative to base_path)}';

    protected $description = 'Wrap hardcoded Filament strings with __() and collect them into lang/en.json';

    /**
     * Chained method names whose first string argument is a display label.
     * ->methodName('literal') → ->methodName(__('literal'))
     */
    private const LABEL_METHODS = [
        'label', 'heading', 'subheading', 'description', 'placeholder',
        'hint', 'helperText', 'tooltip',
        'modalHeading', 'modalDescription', 'modalSubmitActionLabel', 'modalCancelActionLabel',
        'emptyStateHeading', 'emptyStateDescription',
        'title', 'badge', 'body',
        'successNotificationTitle', 'failureNotificationTitle',
    ];

    /**
     * Classes whose ::make('string') first arg is a display name.
     * TextInput::make('field_name') is excluded — see wrapMakeCalls().
     */
    private const MAKE_CLASSES = ['Section', 'Tab', 'Step', 'Fieldset'];

    /**
     * Static property names to replace with translatable method overrides.
     * PHP static props can't contain function calls, so we convert them.
     * Maps: $property => [methodName, returnType]
     */
    private const LABEL_PROPERTIES = [
        'navigationLabel' => ['getNavigationLabel', 'string'],
        'modelLabel' => ['getModelLabel', 'string'],
        'pluralModelLabel' => ['getPluralModelLabel', 'string'],
    ];

    /** All unique English strings found, key === value */
    private array $strings = [];

    /** Absolute paths of files whose content changed */
    private array $replacements = [];

    /** Dynamic strings that were skipped */
    private array $warnings = [];

    public function handle(): int
    {
        $scanPath = base_path($this->option('path'));
        $dryRun = (bool) $this->option('dry-run');

        if (! is_dir($scanPath)) {
            $this->error("Path not found: {$scanPath}");

            return self::FAILURE;
        }

        $existingStrings = $this->loadExistingJson();

        $this->info("Scanning: {$scanPath}");

        foreach (File::allFiles($scanPath) as $file) {
            if ($file->getExtension() === 'php') {
                $this->processFile($file->getRealPath());
            }
        }

        $newCount = count(array_diff_key($this->strings, $existingStrings));
        $totalFiles = count($this->replacements);

        $this->newLine();
        $this->line(
            'Found <comment>'.count($this->strings).'</comment> unique strings '
            ."(<info>{$newCount} new</info>) across <comment>{$totalFiles}</comment> files."
        );

        if ($dryRun) {
            $this->displayDryRun($existingStrings);
        } else {
            $this->applyFileReplacements();
            $this->writeJsonFile();
            $this->info("\nDone. Run <comment>php artisan app:generate-translations</comment> to produce DE/FR/ES files.");
        }

        if (! empty($this->warnings)) {
            $this->newLine();
            $this->warn('Skipped dynamic strings (wrap manually with __() if needed):');
            foreach ($this->warnings as $w) {
                $this->line("  {$w}");
            }
        }

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────
    // File processing
    // ─────────────────────────────────────────────────────────────────────

    private function processFile(string $path): void
    {
        $content = file_get_contents($path);
        $original = $content;

        $content = $this->transformStaticProperties($content, $path);
        $content = $this->wrapMethodCalls($content, $path);
        $content = $this->wrapMakeCalls($content, $path);

        if ($content !== $original) {
            $this->replacements[$path] = $content;
        }
    }

    /**
     * Convert static label properties to method overrides.
     *
     *   protected static ?string $navigationLabel = 'Channels';
     * becomes:
     *   public static function getNavigationLabel(): ?string
     *   {
     *       return __('Channels');
     *   }
     */
    private function transformStaticProperties(string $content, string $path): string
    {
        foreach (self::LABEL_PROPERTIES as $property => [$method, $returnType]) {
            $pattern = '/^([ \t]*)protected\s+static\s+\?string\s+\$'
                .preg_quote($property, '/')
                .'\s*=\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*;[ \t]*$/m';

            $content = preg_replace_callback($pattern, function (array $m) use ($property, $method, $returnType, $path) {
                $indent = $m[1];
                $raw = $m[2];

                if (str_contains($raw, '__')) {
                    return $m[0]; // already wrapped
                }

                if (str_contains($raw, '$') || str_contains($raw, '{')) {
                    $this->warnings[] = "{$path}: \${$property} = {$raw} (dynamic)";

                    return $m[0];
                }

                $value = trim($raw, '\'"');
                // Single-quoted PHP strings only need \\ and \' escaped — not \" (addslashes would produce literal backslashes)
                $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);

                $this->strings[$value] = $value;

                return "{$indent}public static function {$method}(): {$returnType}\n"
                    ."{$indent}{\n"
                    ."{$indent}    return __('{$escaped}');\n"
                    ."{$indent}}";
            }, $content);
        }

        return $content;
    }

    /**
     * Wrap ->methodName('literal') calls.
     */
    private function wrapMethodCalls(string $content, string $path): string
    {
        $methods = implode('|', array_map('preg_quote', self::LABEL_METHODS));
        $pattern = '/->(?:'.$methods.')\s*\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*\)/';

        return preg_replace_callback($pattern, function (array $m) use ($path) {
            return $this->replaceStringArg($m[0], $m[1], $path);
        }, $content);
    }

    /**
     * Wrap Section::make('…'), Tab::make('…'), Step::make('…'), Fieldset::make('…').
     * Excludes form-component make() calls (TextInput, Select, etc.) since those
     * are field name keys, not display labels.
     */
    private function wrapMakeCalls(string $content, string $path): string
    {
        $classes = implode('|', self::MAKE_CLASSES);
        $pattern = '/(?:'.$classes.')::make\s*\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*\)/';

        return preg_replace_callback($pattern, function (array $m) use ($path) {
            return $this->replaceStringArg($m[0], $m[1], $path);
        }, $content);
    }

    /**
     * Core replacement: given the full match string and the captured string literal,
     * wrap with __() if it is a plain translatable string.
     */
    private function replaceStringArg(string $fullMatch, string $literal, string $path): string
    {
        // Already wrapped
        if (str_contains($fullMatch, '__')) {
            return $fullMatch;
        }

        $value = trim($literal, '\'"');

        if ($value === '') {
            return $fullMatch;
        }

        // Skip dynamic content
        if (str_contains($value, '$') || str_contains($value, '{')) {
            $this->warnings[] = "{$path}: dynamic string in {$fullMatch}";

            return $fullMatch;
        }

        // Skip icon names (heroicon-o-*, phosphor-*, etc.)
        if (str_starts_with($value, 'heroicon') || str_starts_with($value, 'phosphor')) {
            return $fullMatch;
        }

        $this->strings[$value] = $value;
        // Single-quoted PHP strings only need \\ and \' escaped — not \" (addslashes would produce literal backslashes)
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);

        return str_replace($literal, "__('{$escaped}')", $fullMatch);
    }

    // ─────────────────────────────────────────────────────────────────────
    // I/O helpers
    // ─────────────────────────────────────────────────────────────────────

    private function loadExistingJson(): array
    {
        $path = lang_path('en.json');

        if (! file_exists($path)) {
            return [];
        }

        $decoded = json_decode(file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function writeJsonFile(): void
    {
        $existing = $this->loadExistingJson();
        $merged = array_merge($existing, $this->strings);
        ksort($merged);

        $path = lang_path('en.json');
        file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $this->line('  <fg=green>Wrote</> lang/en.json ('.count($merged).' keys)');
    }

    private function applyFileReplacements(): void
    {
        foreach ($this->replacements as $filePath => $content) {
            file_put_contents($filePath, $content);
            $this->line('  <fg=blue>Updated</> '.Str::after($filePath, base_path('/')));
        }
    }

    private function displayDryRun(array $existingStrings): void
    {
        $this->newLine();
        $this->line('Strings to be added/updated in lang/en.json:');

        foreach ($this->strings as $value) {
            $tag = array_key_exists($value, $existingStrings) ? '<fg=yellow>(existing)</>' : '<fg=green>(new)    </>';
            $this->line("  {$tag} \"{$value}\"");
        }

        $this->newLine();
        $this->line('Files that would be modified:');

        foreach (array_keys($this->replacements) as $p) {
            $this->line('  '.Str::after($p, base_path('/')));
        }
    }
}
