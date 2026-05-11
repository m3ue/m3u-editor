<?php

namespace App\Listeners;

use App\Services\AlertService;
use App\Settings\GeneralSettings;
use Illuminate\Queue\Events\JobFailed;

class AlertOnJobFailed
{
    public function __construct(
        private readonly GeneralSettings $settings,
        private readonly AlertService $alertService,
    ) {}

    public function handle(JobFailed $event): void
    {
        if (! $this->settings->alerts_on_job_failed) {
            return;
        }

        if (! $this->alertService->isEnabled()) {
            return;
        }

        $jobName = $event->job->resolveName();
        $exception = $event->exception;

        $context = [
            'job' => $jobName,
            'queue' => $event->job->getQueue(),
            'connection' => $event->connectionName,
        ];

        $encoded = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $message = "[JOB FAILED] {$jobName}: {$exception->getMessage()}\n```\n{$encoded}\n```";

        $this->alertService->send($message);
    }
}
