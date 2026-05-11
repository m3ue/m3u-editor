<?php

namespace App\Logging;

use App\Services\AlertService;
use App\Settings\GeneralSettings;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Custom Monolog handler that forwards error-level (and above) log entries
 * to Discord and/or Slack when the respective integrations are enabled in
 * GeneralSettings. Stack traces are intentionally omitted — only the
 * message and any context array are forwarded.
 */
class AlertsHandler extends AbstractProcessingHandler
{
    public function __construct()
    {
        parent::__construct(Level::Error);
    }

    protected function write(LogRecord $record): void
    {
        try {
            /** @var AlertService $alertService */
            $alertService = app(AlertService::class);

            if (! $alertService->isEnabled()) {
                return;
            }

            $alertService->send($this->formatMessage($record));
        } catch (Throwable) {
            // Settings not available (e.g. during early boot or tests).
        }
    }

    /**
     * Build a concise, readable alert message from the log record.
     * Includes the log level, message, and any context — no stack trace.
     */
    private function formatMessage(LogRecord $record): string
    {
        $level = strtoupper($record->level->name);
        $message = "[{$level}] {$record->message}";

        $context = $record->context;

        // Strip Laravel's internal "exception" key to avoid dumping stack traces,
        // but still capture the exception class + message if present.
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $e = $context['exception'];
            $context['exception'] = get_class($e).': '.$e->getMessage();
        }

        if (! empty($context)) {
            $encoded = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $message .= "\n```\n{$encoded}\n```";
        }

        return $message;
    }
}
