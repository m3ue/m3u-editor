<?php

namespace App\Console\Commands;

use App\Enums\DvrRecordingStatus;
use App\Jobs\EnrichDvrMetadata;
use App\Jobs\IntegrateDvrRecordingToVod;
use App\Models\DvrRecording;
use Illuminate\Console\Command;

class EnrichDvrRecordings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dvr:enrich
                            {--id= : Enrich a specific recording by ID}
                            {--all : Re-enrich all completed recordings, even those already enriched}
                            {--integrate-only : Skip metadata enrichment, only re-run VOD integration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enrich completed DVR recordings with metadata and integrate them into the VOD library';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = DvrRecording::where('status', DvrRecordingStatus::Completed)
            ->whereNotNull('file_path');

        if ($this->option('id')) {
            $query->where('id', (int) $this->option('id'));
        } elseif (! $this->option('all')) {
            // Default: only recordings missing metadata or missing a VOD entry
            $query->where(function ($q) {
                $q->whereNull('metadata')
                    ->orWhereJsonLength('metadata', 0)
                    ->orWhereDoesntHave('vodChannel')
                    ->orWhereDoesntHave('vodEpisode');
            });
        }

        $recordings = $query->get();

        if ($recordings->isEmpty()) {
            $this->info('No recordings to enrich.');

            return self::SUCCESS;
        }

        $this->info("Dispatching enrichment for {$recordings->count()} recording(s)...");

        $integrateOnly = $this->option('integrate-only');

        foreach ($recordings as $recording) {
            $label = "[{$recording->id}] {$recording->title}";

            if ($integrateOnly || ! empty($recording->metadata)) {
                // Metadata already present (or skipped) — go straight to VOD integration
                IntegrateDvrRecordingToVod::dispatch($recording->id)->onQueue('dvr-post');
                $this->line("  → Queued VOD integration for {$label}");
            } else {
                // Full enrichment pipeline: metadata → VOD integration
                $setting = $recording->dvrSetting;
                if ($setting && $setting->enable_metadata_enrichment) {
                    EnrichDvrMetadata::dispatch($recording->id)->onQueue('dvr-meta');
                    $this->line("  → Queued metadata enrichment for {$label}");
                } else {
                    IntegrateDvrRecordingToVod::dispatch($recording->id)->onQueue('dvr-post');
                    $this->line("  → Queued VOD integration (enrichment disabled) for {$label}");
                }
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
