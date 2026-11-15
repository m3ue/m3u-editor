<?php

namespace App\Jobs;

use App\Logging\AlertsHandler;
use App\Mail\AlertMail;
use App\Services\SmtpMailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers an alert to the configured email recipients.
 *
 * Queued like the Discord, Slack and Telegram channels so a slow or unreachable
 * SMTP server never blocks the caller (AlertService is called from inside
 * log writes). SMTP is applied in the worker, because config set in the
 * dispatching process does not carry over to the queued job.
 */
class SendAlertEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 3;

    /**
     * @param  array<int, string>  $recipients
     */
    public function __construct(
        public array $recipients,
        public string $emailSubject,
        public string $body,
    ) {}

    public function handle(SmtpMailService $smtp): void
    {
        if ($this->recipients === [] || ! $smtp->isConfigured()) {
            return;
        }

        $smtp->apply();

        Mail::to($this->recipients)->send(new AlertMail(
            emailSubject: $this->emailSubject,
            body: $this->body,
        ));
    }

    public function failed(Throwable $exception): void
    {
        Log::error("Email alert job failed: {$exception->getMessage()}", [
            AlertsHandler::SKIP_CONTEXT_KEY => true,
        ]);
    }
}
