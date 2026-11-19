<?php

namespace App\Services;

use App\Enums\EpgSourceType;
use App\Models\CachedContentFile;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Epg;
use App\Models\MediaServerIntegration;
use App\Models\Plugin;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\MasterSupervisorCommands\AddSupervisor;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;
use Throwable;

/**
 * Starts the Horizon supervisors of optional features only once the feature is used.
 *
 * Horizon keeps at least one idle worker per queue, plus the supervisor process, for
 * every supervisor it runs. These supervisors serve features many installs never use,
 * so when Horizon starts it leaves out the ones whose feature is unused and has no
 * queued jobs. A once-a-minute check then starts a left-out supervisor, with its
 * normal config, as soon as the feature is used or a job lands on one of its queues.
 *
 * A started supervisor keeps running until Horizon restarts. When a check fails, the
 * supervisor is treated as needed, so it runs exactly as it did before.
 */
class OptionalQueueSupervisors
{
    /**
     * How long a start request suppresses another one for the same supervisor,
     * while Horizon starts it and records it in its master's supervisor list.
     */
    private const START_REQUEST_SECONDS = 300;

    /**
     * Whether each optional supervisor's feature is in use, keyed by supervisor name
     * in config/horizon.php.
     *
     * @return array<string, Closure(): bool>
     */
    public function featureChecks(): array
    {
        return [
            'm3u-editor-plugin-queue' => fn (): bool => Plugin::query()->where('enabled', true)->exists(),
            'm3u-editor-sd-queue' => fn (): bool => Epg::query()
                ->where('source_type', EpgSourceType::SCHEDULES_DIRECT->value)
                ->exists(),
            'dvr-queue' => fn (): bool => self::dvrInUse(),
            'aiostreams-queue' => fn (): bool => MediaServerIntegration::query()->where('type', 'aiostreams')->exists(),
            'cache-queue' => fn (): bool => CachedContentFile::query()->exists(),
        ];
    }

    /**
     * DVR is in use once a playlist has it switched on, or recordings exist that
     * still need retention, post-processing or metadata work.
     */
    public static function dvrInUse(): bool
    {
        return DvrSetting::query()->where('enabled', true)->exists()
            || DvrRecording::query()->exists();
    }

    /**
     * Called as the Horizon master starts, before it starts its supervisors: sets
     * maxProcesses to 0 for each unneeded supervisor, which Horizon skips.
     *
     * @return list<string> The supervisors left out.
     */
    public function leaveOutUnusedSupervisors(): array
    {
        $leftOut = [];

        foreach ($this->featureChecks() as $supervisor => $isInUse) {
            $options = config("horizon.defaults.{$supervisor}");

            if (! is_array($options) || $this->isNeeded($isInUse, $options)) {
                continue;
            }

            config(["horizon.defaults.{$supervisor}.maxProcesses" => 0]);
            $leftOut[] = $supervisor;
        }

        if ($leftOut !== []) {
            Log::info('Horizon: not starting unused optional supervisors until their feature is used.', [
                'supervisors' => $leftOut,
            ]);
        }

        return $leftOut;
    }

    /**
     * Asks each running Horizon master to start the optional supervisors it left
     * out at startup whose feature is now in use or that have queued jobs.
     *
     * @return list<string> The supervisors asked to start, as "master:supervisor".
     */
    public function startNeededSupervisors(): array
    {
        $masterRepository = app(MasterSupervisorRepository::class);
        $masters = $masterRepository->all();

        if ($masters === []) {
            return [];
        }

        $needed = [];

        foreach ($this->featureChecks() as $supervisor => $isInUse) {
            $options = config("horizon.defaults.{$supervisor}");

            if (is_array($options) && $this->isNeeded($isInUse, $options)) {
                $needed[] = $supervisor;
            }
        }

        $started = [];

        foreach ($masters as $master) {
            $running = $master->supervisors ?? [];

            foreach ($needed as $supervisor) {
                $name = "{$master->name}:{$supervisor}";

                if (in_array($name, $running, true) || Cache::has($this->startRequestKey($name))) {
                    continue;
                }

                $options = $this->supervisorOptions($master->name, (string) $master->environment, $supervisor);

                if ($options === null || $options->maxProcesses < 1) {
                    continue;
                }

                $options->parentId = (int) $master->pid;

                app(HorizonCommandQueue::class)->push(
                    MasterSupervisor::commandQueueFor($master->name),
                    AddSupervisor::class,
                    $options->toArray(),
                );

                Cache::put($this->startRequestKey($name), true, self::START_REQUEST_SECONDS);
                $started[] = $name;
            }
        }

        if ($started !== []) {
            Log::info('Horizon: starting optional supervisors now that their feature is in use.', [
                'supervisors' => $started,
            ]);
        }

        return $started;
    }

    /**
     * @param  Closure(): bool  $isInUse
     * @param  array<string, mixed>  $options
     */
    private function isNeeded(Closure $isInUse, array $options): bool
    {
        try {
            return $isInUse() || $this->hasQueuedJobs($options);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Counts waiting, delayed and running jobs, so a job never sits on a queue
     * nobody works, whatever dispatched it.
     *
     * @param  array<string, mixed>  $options
     */
    private function hasQueuedJobs(array $options): bool
    {
        $connection = Queue::connection($options['connection'] ?? null);

        foreach ((array) ($options['queue'] ?? []) as $queue) {
            if ($connection->size($queue) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The supervisor's options from the current config, built as Horizon builds them
     * at startup: the environment block is matched the same way (e.g. "*").
     */
    private function supervisorOptions(string $master, string $environment, string $supervisor): ?SupervisorOptions
    {
        $plan = ProvisioningPlan::get($master);

        foreach ($plan->environments() as $pattern) {
            if (Str::is($pattern, $environment)) {
                return $plan->optionsFor($pattern, $supervisor);
            }
        }

        return null;
    }

    private function startRequestKey(string $name): string
    {
        return 'horizon:optional-supervisor-start:'.$name;
    }
}
