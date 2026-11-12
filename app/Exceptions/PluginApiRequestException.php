<?php

namespace App\Exceptions;

use Exception;

/**
 * A rejected or failed plugin API request. Carries only a status and a fixed
 * error code (plus field names for invalid payloads), never plugin exception
 * messages, so nothing internal reaches the caller.
 */
class PluginApiRequestException extends Exception
{
    /**
     * @param  array<int, string>  $fields
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly array $fields = [],
    ) {
        parent::__construct($errorCode, $status);
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found');
    }

    public static function forbidden(): self
    {
        return new self(403, 'plugin_unavailable');
    }

    /**
     * @param  array<int, string>  $fields
     */
    public static function invalidPayload(array $fields): self
    {
        return new self(422, 'invalid_payload', array_values(array_filter($fields, 'is_string')));
    }

    public static function failed(): self
    {
        return new self(500, 'plugin_request_failed');
    }
}
