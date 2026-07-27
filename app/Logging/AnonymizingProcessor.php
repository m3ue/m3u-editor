<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Redacts sensitive fields before a record reaches any configured handler.
 */
class AnonymizingProcessor implements ProcessorInterface
{
    private const string URL_PATTERN = '/(?:https?|rtmps?|ftps?|hls):\/\/[^\s"\'<>\[\]{}\|\\\\^`]+/i';

    /** Matches key=value, key: value, key='value', key="value". Group 1: keyword, group 2: optional quote, group 3: value. */
    private const string USER_PATTERN = '/\b(username|user|login|ip|host|hostname|server)\s*[:=]\s*([\'"]?)([^&\s"\'<>#,\)\]]+)\2/i';

    /** UUIDs identify specific resources and can be used to probe APIs */
    private const string UUID_PATTERN = '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i';

    private const array SENSITIVE_KEY_TERMS = [
        'authorization',
        'cookie',
        'credential',
        'headers',
        'password',
        'secret',
        'session',
        'source',
        'token',
        'url',
    ];

    private bool $enabled;

    public function __construct()
    {
        $this->enabled = (bool) env('LOG_ANONYMIZE', true);
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->scrub($record->message),
            context: $this->scrubArray($record->context),
        );
    }

    private function scrub(string $text): string
    {
        $text = (string) preg_replace(self::URL_PATTERN, '[REDACTED]', $text);

        if ($this->enabled) {
            $text = (string) preg_replace(self::USER_PATTERN, '$1=$2[REDACTED]$2', $text);
            $text = (string) preg_replace_callback(
                self::UUID_PATTERN,
                static fn (array $matches): string => '[id:'.substr(hash('sha256', strtolower($matches[0])), 0, 12).']',
                $text,
            );
        }

        return $text;
    }

    private function scrubArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                unset($data[$key]);

                continue;
            }

            if (\is_string($value)) {
                $data[$key] = $this->scrub($value);
            } elseif (\is_array($value)) {
                $data[$key] = $this->scrubArray($value);
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = (string) preg_replace('/[^a-z0-9]/', '', strtolower($key));

        foreach (self::SENSITIVE_KEY_TERMS as $term) {
            if (str_contains($normalizedKey, $term)) {
                return true;
            }
        }

        return str_ends_with($normalizedKey, 'key');
    }
}
