<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lang:merge-conflicts')]
#[Description('Resolve git merge conflicts in JSON language files by unioning both sides, and re-sort every file alphabetically (runs even when there are no conflicts).')]
class MergeLangConflicts extends Command
{
    public function handle(): int
    {
        $files = glob(lang_path('*.json'));

        if (empty($files)) {
            $this->error('No JSON files found in '.lang_path());

            return self::FAILURE;
        }

        $resolved = 0;
        $sorted = 0;

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $hadConflict = str_contains($content, '<<<<<<<');

            $normalized = $hadConflict
                ? $this->resolveConflicts($content, basename($file))
                : $this->normalize($content, basename($file));

            if ($normalized === null) {
                return self::FAILURE;
            }

            if ($normalized === $content) {
                continue;
            }

            file_put_contents($file, $normalized);

            if ($hadConflict) {
                $this->line('<info>Resolved:</info> '.basename($file));
                $resolved++;
            } else {
                $this->line('<info>Sorted:</info> '.basename($file));
                $sorted++;
            }
        }

        $resolved > 0
            ? $this->info("Resolved conflicts in {$resolved} file(s).")
            : $this->info('No conflicts found in any lang JSON files.');

        if ($sorted > 0) {
            $this->info("Re-sorted {$sorted} conflict-free file(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Re-encode a conflict-free file so its keys are sorted and its formatting matches
     * what resolveConflicts() would produce, keeping the two paths byte-for-byte consistent.
     */
    private function normalize(string $content, string $filename): ?string
    {
        $json = @json_decode($content, true);

        if (! is_array($json)) {
            $this->error("Could not parse JSON in {$filename} — resolve manually.");

            return null;
        }

        return $this->encode($json);
    }

    /**
     * @param  array<string, mixed>  $translations
     */
    private function encode(array $translations): string
    {
        ksort($translations);

        return json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }

    private function resolveConflicts(string $content, string $filename): ?string
    {
        // Build two complete JSON strings by routing each line to HEAD or THEIRS
        // depending on which conflict section it falls in.
        $headLines = [];
        $theirLines = [];
        $inConflict = false;
        $inHead = false;

        foreach (explode("\n", $content) as $line) {
            if (str_starts_with($line, '<<<<<<<')) {
                $inConflict = true;
                $inHead = true;

                continue;
            }

            if ($line === '=======' && $inConflict) {
                $inHead = false;

                continue;
            }

            if (str_starts_with($line, '>>>>>>>') && $inConflict) {
                $inConflict = false;

                continue;
            }

            if (! $inConflict) {
                $headLines[] = $line;
                $theirLines[] = $line;
            } elseif ($inHead) {
                $headLines[] = $line;
            } else {
                $theirLines[] = $line;
            }
        }

        $headJson = @json_decode(implode("\n", $headLines), true);
        $theirJson = @json_decode(implode("\n", $theirLines), true);

        if (! is_array($headJson) || ! is_array($theirJson)) {
            $this->error("Could not parse JSON from conflict sections in {$filename} — resolve manually.");

            return null;
        }

        // Union both sides; HEAD (current branch) wins for any duplicate key so
        // existing translations aren't overwritten by an older value from the
        // incoming branch. array_merge() would renumber purely-numeric-string
        // keys (e.g. "9", "10") instead of treating them as translation keys,
        // corrupting their values — use the array union operator instead, which
        // preserves all keys as-is.
        return $this->encode($headJson + $theirJson);
    }
}
