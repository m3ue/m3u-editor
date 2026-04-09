---
title: Never Suppress Errors
impact: CRITICAL
impactDescription: Suppressed errors hide bugs and make debugging nearly impossible
tags: error-handling, error-suppression, at-operator, php8
---

# Never Suppress Errors

Never use the `@` error suppression operator. Handle errors explicitly instead.

## Bad Example

```php
<?php

declare(strict_types=1);

// @ hides all errors - bugs become invisible
$data = @file_get_contents('/path/to/file');
$value = @json_decode($json);
$result = @unserialize($data);
$conn = @mysqli_connect('localhost', 'user', 'pass');

// Silently returns false/null with no indication of what went wrong
if ($data === false) {
    // Was it file not found? Permission denied? Disk error? No way to know
}

// Empty catch blocks are equally bad
try {
    $service->process($data);
} catch (\Exception $e) {
    // Swallowed - same as @
}
```

## Good Example

```php
<?php

declare(strict_types=1);

// Check before acting
if (!is_readable($path)) {
    throw new FileNotFoundException("File not readable: {$path}");
}
$data = file_get_contents($path);
if ($data === false) {
    throw new FileReadException("Failed to read: {$path}");
}

// Use json_validate (8.3+) or check decode errors
$decoded = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    throw new JsonParseException(json_last_error_msg());
}

// PHP 8.3+: validate before decoding
if (!json_validate($json)) {
    throw new JsonParseException('Invalid JSON input');
}

// Use exceptions instead of error returns
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (\PDOException $e) {
    $logger->error('Database connection failed', [
        'error' => $e->getMessage(),
    ]);
    throw new DatabaseConnectionException('Cannot connect to database', previous: $e);
}

// Log if you intentionally skip an error
try {
    $mailer->send($notification);
} catch (MailerException $e) {
    $logger->warning('Non-critical email failed', [
        'error' => $e->getMessage(),
    ]);
    // Intentionally continuing - email is non-critical
}
```

## Why

- **Bugs Stay Visible**: Errors surface immediately where they occur
- **Performance**: `@` is slow - PHP still generates the error internally
- **Debugging**: Stack traces and error messages are preserved
- **Explicit Intent**: If you skip an error, a comment and log explain why
- **Static Analysis**: Tools like PHPStan flag `@` usage as a code smell
