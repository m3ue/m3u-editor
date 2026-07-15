<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lang:merge-conflicts')]
#[Description('Resolve git merge conflicts in JSON language files by unioning both sides and re-sorting alphabetically.')]
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

        foreach ($files as $file) {
            $content = file_get_contents($file);

            if (! str_contains($content, '<<<<<<<')) {
                continue;
            }

            $merged = $this->resolveConflicts($content, basename($file));

            if ($merged === null) {
                return self::FAILURE;
            }

            file_put_contents($file, $merged);
            $this->line('<info>Resolved:</info> '.basename($file));
            $resolved++;
        }

        $resolved > 0
            ? $this->info("Resolved conflicts in {$resolved} file(s).")
            : $this->info('No conflicts found in any lang JSON files.');

        return self::SUCCESS;
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
        // incoming branch.
        $merged = array_merge($theirJson, $headJson);
        ksort($merged);

        return json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }
}
