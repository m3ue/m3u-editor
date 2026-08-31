<?php

namespace App\Console\Commands;

use App\Enums\EpgSourceType;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\Playlist;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ReconcileProviderEpgs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reconcile-provider-epgs
        {--apply : Persist changes (default is a dry run that only reports)}
        {--prune : With --apply, delete duplicate provider EPGs that have no mappings and no mapped channels}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tie provider-created EPGs to their playlist and report/clean up duplicates left behind by URL-matching failover.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $prune = (bool) $this->option('prune');

        if ($prune && ! $apply) {
            $this->warn('--prune has no effect without --apply. Running as a dry run.');
        }

        $this->info($apply ? 'Reconciling provider EPGs (applying changes)...' : 'Reconciling provider EPGs (dry run)...');

        $tied = 0;
        $pruned = 0;
        $conflicts = 0;
        $rows = [];

        Playlist::query()
            ->where('xtream', true)
            ->with('user')
            ->cursor()
            ->each(function (Playlist $playlist) use (&$tied, &$pruned, &$conflicts, &$rows, $apply, $prune) {
                if (! data_get($playlist->xtream_config, 'import_epg')) {
                    return;
                }

                $providerHosts = $this->providerHosts($playlist);
                if ($providerHosts->isEmpty()) {
                    return;
                }

                $candidates = Epg::query()
                    ->where('user_id', $playlist->user_id)
                    ->where('source_type', EpgSourceType::URL)
                    ->where('is_merged', false)
                    ->whereNotNull('url')
                    ->where('url', 'like', 'http%')
                    ->get()
                    ->filter(function (Epg $epg) use ($providerHosts) {
                        $host = parse_url((string) $epg->url, PHP_URL_HOST);
                        $path = (string) parse_url((string) $epg->url, PHP_URL_PATH);

                        return $host
                            && $providerHosts->contains($host)
                            && str_ends_with($path, 'xmltv.php');
                    });

                if ($candidates->isEmpty()) {
                    return;
                }

                // Leave EPGs already tied to a different playlist untouched.
                $foreign = $candidates->filter(
                    fn (Epg $epg) => $epg->playlist_id !== null && $epg->playlist_id !== $playlist->id
                );
                foreach ($foreign as $epg) {
                    $conflicts++;
                    $rows[] = [$playlist->name, $epg->id, $epg->name, "tied to playlist #{$epg->playlist_id}", 'skipped'];
                }
                $candidates = $candidates->diff($foreign);
                if ($candidates->isEmpty()) {
                    return;
                }

                $keeper = $this->pickKeeper($candidates, $playlist->id);
                $extras = $candidates->filter(fn (Epg $epg) => $epg->id !== $keeper->id);

                if ($keeper->playlist_id !== $playlist->id) {
                    $tied++;
                    $rows[] = [$playlist->name, $keeper->id, $keeper->name, 'untied', $apply ? 'tied (keeper)' : 'would tie (keeper)'];
                    if ($apply) {
                        $keeper->update(['playlist_id' => $playlist->id]);
                    }
                } else {
                    $rows[] = [$playlist->name, $keeper->id, $keeper->name, 'already tied', 'keeper'];
                }

                foreach ($extras as $epg) {
                    $referenced = $epg->epgMaps()->exists()
                        || Channel::query()
                            ->whereIn('epg_channel_id', $epg->channels()->select('id'))
                            ->exists();

                    if ($prune && $apply && ! $referenced) {
                        $epg->delete();
                        $pruned++;
                        $rows[] = [$playlist->name, $epg->id, $epg->name, 'duplicate', 'pruned'];
                    } elseif (! $referenced) {
                        $rows[] = [$playlist->name, $epg->id, $epg->name, 'duplicate', $prune ? 'would prune' : 'unreferenced duplicate'];
                    } else {
                        $rows[] = [$playlist->name, $epg->id, $epg->name, 'duplicate', 'in use - review manually'];
                    }
                }
            });

        if (empty($rows)) {
            $this->info('Nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->table(['Playlist', 'EPG ID', 'EPG Name', 'Before', 'Action'], $rows);
        $this->line('');
        $this->info("Keeper ties: {$tied}   Pruned: {$pruned}   Conflicts left alone: {$conflicts}");

        if (! $apply) {
            $this->comment('Dry run only. Re-run with --apply (optionally --prune) to persist.');
        }

        return self::SUCCESS;
    }

    /**
     * Distinct hosts across a playlist's primary + fallback Xtream URLs.
     *
     * @return Collection<int, string>
     */
    protected function providerHosts(Playlist $playlist): Collection
    {
        return collect($playlist->getOrderedXtreamUrls())
            ->map(fn (string $url): ?string => parse_url($url, PHP_URL_HOST))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Prefer an EPG already tied to this playlist, otherwise the one with the
     * most channels, tie-broken by most recent sync.
     *
     * @param  Collection<int, Epg>  $candidates
     */
    protected function pickKeeper(Collection $candidates, int $playlistId): Epg
    {
        $alreadyTied = $candidates->firstWhere('playlist_id', $playlistId);
        if ($alreadyTied) {
            return $alreadyTied;
        }

        return $candidates
            ->sortByDesc(fn (Epg $epg) => [$epg->channels()->count(), optional($epg->synced)->timestamp ?? 0])
            ->first();
    }
}
