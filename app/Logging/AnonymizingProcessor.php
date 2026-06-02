<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that redacts URLs and usernames from log messages and
 * context arrays. Controlled by LOG_ANONYMIZE env variable (default: true).
 */
class AnonymizingProcessor implements ProcessorInterface
{
    private const string URL_PATTERN = '/(?:https?|rtmps?|ftps?|hls):\/\/[^\s"\'<>\[\]{}\|\\\\^`]+/i';

    /** Matches key=value or key: value; stops before & ) space quote etc. */
    private const string USER_PATTERN = '/\b(username|user|login|ip)\s*[:=]\s*([^&\s"\'<>#,\)\]]+)/i';

    /** UUIDs identify specific resources and can be used to probe APIs */
    private const string UUID_PATTERN = '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i';

    private bool $enabled;

    public function __construct()
    {
        $this->enabled = (bool) env('LOG_ANONYMIZE', true);
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if (! $this->enabled) {
            return $record;
        }

        return $record->with(
            message: $this->scrub($record->message),
            context: $this->scrubArray($record->context),
        );
    }

    private function scrub(string $text): string
    {
        $text = (string) preg_replace(self::URL_PATTERN, '****', $text);
        $text = (string) preg_replace(self::USER_PATTERN, '$1=****', $text);
        $text = (string) preg_replace(self::UUID_PATTERN, '****', $text);

        return $text;
    }

    private function scrubArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($value)) {
                $data[$key] = $this->scrub($value);
            } elseif (\is_array($value)) {
                $data[$key] = $this->scrubArray($value);
            }
        }

        return $data;
    }
}
