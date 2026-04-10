<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Stichoza\GoogleTranslate\GoogleTranslate;
use Throwable;

/**
 * Auto-translates lang/en/*.php source files into one or more target locales
 * using the free stichoza/google-translate-php library (no API key required).
 *
 * - Incremental: only translates keys missing from the target locale file.
 * - Use --force to re-translate all keys (quality refresh).
 * - Safe to re-run before every release.
 *
 * Usage:
 *   php artisan app:generate-translations
 *   php artisan app:generate-translations --locale=de
 *   php artisan app:generate-translations --locale=de,fr --force
 */
class GenerateTranslations extends Command
{
    protected $signature = 'app:generate-translations
        {--locale= : Comma-separated locales to generate (default: de,fr,es)}
        {--force : Re-translate all keys, even existing ones}';

    protected $description = 'Auto-translate lang/en/ source files into DE, FR, ES (and other) locales';

    /** Microseconds to sleep between API calls to respect rate limits */
    private const SLEEP_US = 200_000; // 200 ms

    /** Default locales to generate when --locale is omitted */
    private const DEFAULT_LOCALES = ['de', 'fr', 'es', 'zh_CN'];

    /**
     * Brand names / proper nouns that must never be translated.
     * When the entire source value exactly matches one of these (case-insensitively),
     * the EN value is kept as-is without sending it to Google Translate.
     */
    private const PROTECTED_EXACT = [
        // Media server / app brand names
        'Jellyfin', 'Plex', 'Emby', 'Kodi', 'Infuse', 'VLC',
        // Database / metadata brands
        'TMDB', 'TVDB', 'IMDB',
        // *arr ecosystem
        'Sonarr', 'Radarr', 'Prowlarr', 'Bazarr', 'Readarr', 'Lidarr', 'Whisparr',
        // Communication / community
        'Discord', 'Ko-fi',
        // Protocols / formats
        'WebDAV', 'M3U', 'XMLTV', 'EPG', 'VOD', 'Xtream',
        // Other proper nouns
        'Trakt', 'GitHub',
    ];

    public function handle(): int
    {
        $locales = $this->resolveLocales();
        $force = (bool) $this->option('force');

        // PHP array files (lang/en/*.php → lang/{locale}/*.php)
        $phpSource = $this->loadSourceTranslations();

        // JSON strings (lang/en.json → lang/{locale}.json)
        $jsonSource = $this->loadSourceJson();

        if (empty($phpSource) && empty($jsonSource)) {
            $this->warn('No source translations found. Run app:extract-translations first.');

            return self::FAILURE;
        }

        $phpTotal = array_sum(array_map(fn ($a) => count(Arr::dot($a)), $phpSource));
        $jsonTotal = count($jsonSource);

        $this->info("Source: <comment>{$phpTotal}</comment> PHP-array keys + <comment>{$jsonTotal}</comment> JSON string keys");
        $this->newLine();

        foreach ($locales as $locale) {
            $this->line("<fg=cyan>━━━ Locale: {$locale} ━━━</>");

            if (! empty($phpSource)) {
                $flatSource = Arr::dot(collect($phpSource)->mapWithKeys(fn ($v, $k) => [$k => $v])->all());
                // Re-flatten correctly
                $merged = [];
                foreach ($phpSource as $file => $array) {
                    foreach (Arr::dot($array) as $k => $v) {
                        $merged[$k] = $v;
                    }
                }
                $this->processPhpLocale($locale, $merged, $phpSource, $force);
            }

            if (! empty($jsonSource)) {
                $this->processJsonLocale($locale, $jsonSource, $force);
            }

            $this->newLine();
        }

        $this->info('Done. Review generated files in lang/{locale}/ before committing.');

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────
    // Per-locale processing
    // ─────────────────────────────────────────────────────────────

    /** Translate PHP-array files (lang/en/*.php → lang/{locale}/*.php). */
    private function processPhpLocale(string $locale, array $flatSource, array $sourceByFile, bool $force): void
    {
        $existing = $this->loadExistingTranslations($locale);
        // Flatten WITHOUT the filename prefix to match the key format used by $merged
        $flatExisting = [];
        foreach ($existing as $fileArray) {
            foreach (Arr::dot($fileArray) as $k => $v) {
                $flatExisting[$k] = $v;
            }
        }

        $toTranslate = $force
            ? $flatSource
            : array_diff_key($flatSource, $flatExisting);

        $skipped = count($flatSource) - count($toTranslate);
        $new = count($toTranslate);

        $this->line("  PHP arrays: <comment>{$new} new</comment> / {$skipped} existing");

        if ($new === 0) {
            return;
        }

        $translator = new GoogleTranslate($locale);
        $translated = $flatExisting;
        $errors = 0;
        $bar = $this->output->createProgressBar($new);
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->start();

        foreach ($toTranslate as $dotKey => $value) {
            $bar->setMessage(Str::limit($dotKey, 40));

            if (is_array($value)) {
                // Empty or non-string leaf (e.g. [] placeholder) — keep as-is
                $translated[$dotKey] = $value;
            } elseif ($this->shouldSkipTranslation((string) $value)) {
                $translated[$dotKey] = $value;
            } else {
                [$protected, $map] = $this->protectPlaceholders((string) $value);
                try {
                    $result = $translator->translate($protected) ?? $protected;
                    $translated[$dotKey] = $this->restorePlaceholders($result, $map);
                } catch (Throwable $e) {
                    $translated[$dotKey] = $value;
                    $errors++;
                }
                usleep(self::SLEEP_US);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($errors > 0) {
            $this->warn("  {$errors} PHP keys fell back to EN. Re-run to retry.");
        }

        $this->writeLocaleFiles($locale, $translated, $sourceByFile);
    }

    /** Translate JSON strings (lang/en.json → lang/{locale}.json). */
    private function processJsonLocale(string $locale, array $source, bool $force): void
    {
        $existing = $this->loadExistingJson($locale);
        $toTranslate = $force
            ? $source
            : array_diff_key($source, $existing);

        $skipped = count($source) - count($toTranslate);
        $new = count($toTranslate);

        $this->line("  JSON strings: <comment>{$new} new</comment> / {$skipped} existing");

        if ($new === 0) {
            return;
        }

        $translator = new GoogleTranslate($locale);
        $translated = $existing;
        $errors = 0;
        $bar = $this->output->createProgressBar($new);
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->start();

        foreach ($toTranslate as $en => $value) {
            $bar->setMessage(Str::limit($en, 40));

            if ($this->shouldSkipTranslation((string) $value)) {
                $translated[$en] = $value;
            } else {
                [$protected, $map] = $this->protectPlaceholders((string) $value);
                try {
                    $result = $translator->translate($protected) ?? $protected;
                    $translated[$en] = $this->restorePlaceholders($result, $map);
                } catch (Throwable $e) {
                    $translated[$en] = $value;
                    $errors++;
                }
                usleep(self::SLEEP_US);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($errors > 0) {
            $this->warn("  {$errors} JSON keys fell back to EN. Re-run to retry.");
        }

        ksort($translated);
        $path = lang_path("{$locale}.json");
        file_put_contents($path, json_encode($translated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        $this->line("  <fg=green>Wrote</> lang/{$locale}.json (".count($translated).' keys)');
    }

    // ─────────────────────────────────────────────────────────────
    // File I/O
    // ─────────────────────────────────────────────────────────────

    /**
     * Load all lang/en/*.php files, returning [filename => nested_array].
     */
    private function loadSourceTranslations(): array
    {
        return $this->loadTranslationsForLocale('en');
    }

    /**
     * Load lang/en.json as the JSON source of truth.
     * Returns [english_string => english_string].
     */
    private function loadSourceJson(): array
    {
        return $this->loadExistingJson('en');
    }

    /**
     * Load existing lang/{locale}.json, returns [key => translated_value].
     */
    private function loadExistingJson(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        if (! file_exists($path)) {
            return [];
        }

        $decoded = json_decode(file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Load existing translations for a locale, returning flat dot-notation array.
     */
    private function loadExistingTranslations(string $locale): array
    {
        return $this->loadTranslationsForLocale($locale);
    }

    private function loadTranslationsForLocale(string $locale): array
    {
        $langPath = lang_path($locale);
        $result = [];

        if (! is_dir($langPath)) {
            return $result;
        }

        foreach (File::files($langPath) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $name = $file->getFilenameWithoutExtension();
            $array = include $file->getRealPath();
            if (is_array($array)) {
                $result[$name] = $array;
            }
        }

        return $result;
    }

    /**
     * Write translated keys back to locale-specific PHP files, preserving
     * the same file breakdown as the EN source.
     */
    private function writeLocaleFiles(string $locale, array $flatTranslated, array $sourceByFile): void
    {
        $langPath = lang_path($locale);

        // Build a per-file flat map from the source structure
        foreach ($sourceByFile as $fileName => $sourceArray) {
            $flatSource = Arr::dot($sourceArray);
            $fileTranslated = [];

            foreach (array_keys($flatSource) as $dotKey) {
                $fullKey = $dotKey; // keys in $flatTranslated may be prefixed with file?
                // The dot keys stored in $flatTranslated use the raw dot key from Arr::dot
                // of the merged source — so they match $dotKey directly.
                if (isset($flatTranslated[$dotKey])) {
                    $fileTranslated[$dotKey] = $flatTranslated[$dotKey];
                }
            }

            // Re-nest into array
            $nested = [];
            foreach ($fileTranslated as $dk => $v) {
                Arr::set($nested, $dk, $v);
            }

            if (empty($nested)) {
                continue;
            }

            $dir = $langPath;
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $export = "<?php\n\nreturn ".$this->varExport($nested).";\n";
            file_put_contents("{$dir}/{$fileName}.php", $export);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * @return string[]
     */
    private function resolveLocales(): array
    {
        $opt = $this->option('locale');
        if ($opt) {
            return array_map('trim', explode(',', $opt));
        }

        return self::DEFAULT_LOCALES;
    }

    /**
     * Returns true when the value is a protected brand name / proper noun
     * that should never be sent to Google Translate.
     * Comparison is case-insensitive on the trimmed value.
     */
    private function shouldSkipTranslation(string $value): bool
    {
        $trimmed = trim($value);

        foreach (self::PROTECTED_EXACT as $brand) {
            if (strcasecmp($trimmed, $brand) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace Laravel :placeholder tokens with neutral markers before translation.
     * Prevents Google Translate from mangling :name, :count, :date, etc.
     * Returns the protected string and a map of token → original placeholder.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function protectPlaceholders(string $value): array
    {
        $map = [];
        $index = 0;

        $protected = preg_replace_callback('/:[a-zA-Z_][a-zA-Z0-9_]*/', function (array $m) use (&$map, &$index) {
            $token = "{PH{$index}}";
            $map[$token] = $m[0];
            $index++;

            return $token;
        }, $value);

        return [$protected, $map];
    }

    /**
     * Restore :placeholder tokens that were protected before translation.
     *
     * @param  array<string, string>  $map
     */
    private function restorePlaceholders(string $translated, array $map): string
    {
        if (empty($map)) {
            return $translated;
        }

        return strtr($translated, $map);
    }

    /**
     * Pretty-print a PHP array for writing to a lang file.
     */
    private function varExport(array $array, int $indent = 0): string
    {
        $pad = str_repeat('    ', $indent);
        $pad1 = str_repeat('    ', $indent + 1);
        $out = "[\n";

        foreach ($array as $k => $v) {
            $exportedKey = is_string($k) ? "'{$k}'" : $k;
            if (is_array($v)) {
                $out .= "{$pad1}{$exportedKey} => ".$this->varExport($v, $indent + 1).",\n";
            } else {
                $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $v);
                $out .= "{$pad1}{$exportedKey} => '{$escaped}',\n";
            }
        }

        return $out."{$pad}]";
    }
}
