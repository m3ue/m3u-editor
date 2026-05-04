<?php

namespace App\Console\Commands;

use App\Enums\DvrRecordingStatus;
use App\Jobs\GenerateDvrNfo;
use App\Models\DvrRecording;
use App\Services\NfoService;
use Illuminate\Console\Command;

/**
 * dvr:generate-nfos — Backfill NFO sidecar files for existing DVR recordings.
 *
 * Only processes recordings whose playlist has generate_nfo_files=true.
 *
 * Filters:
 *   --recording=ID    only one recording
 *   --playlist=ID     all recordings whose dvr_setting belongs to this playlist
 *   --all             all eligible recordings across every playlist
 */
class GenerateDvrNfos extends Command
{
    protected $signature = 'dvr:generate-nfos
                            {--recording= : Generate NFO for a single recording ID}
                            {--playlist= : Limit to recordings on this playlist ID}
                            {--all : Process all recordings across every playlist (generate_nfo_files must still be enabled)}
                            {--sync : Run the job synchronously instead of dispatching to the queue}';

    protected $description = 'Backfill .nfo sidecar files for existing DVR recordings';

    public function handle(): int
    {
        $query = DvrRecording::query()
            ->where('status', DvrRecordingStatus::Completed)
            ->whereNotNull('file_path')
            ->whereHas('dvrSetting', fn ($q) => $q->where('generate_nfo_files', true))
            ->with('dvrSetting');

        if ($id = $this->option('recording')) {
            $query->where('id', (int) $id);
        }

        if ($playlistId = $this->option('playlist')) {
            $query->whereHas('dvrSetting', function ($q) use ($playlistId) {
                $q->where('playlist_id', (int) $playlistId);
            });
        }

        $recordings = $query->get();

        if ($recordings->isEmpty()) {
            $this->info('No recordings match — nothing to do.');

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');
        $this->info(($sync ? 'Running' : 'Dispatching')." NFO generation for {$recordings->count()} recording(s)...");

        foreach ($recordings as $recording) {
            $label = "[{$recording->id}] {$recording->title}";

            if ($sync) {
                try {
                    set_time_limit(30); // match job timeout to prevent indefinite blocking
                    (new GenerateDvrNfo($recording->id))->handle(app(NfoService::class));
                    $this->line("  ✓ {$label}");
                } catch (\Throwable $e) {
                    $this->error("  ✗ {$label}: {$e->getMessage()}");
                }
            } else {
                GenerateDvrNfo::dispatch($recording->id)->onQueue('dvr-meta');
                $this->line("  → queued {$label}");
            }
        }

        return self::SUCCESS;
    }
}
