---
title: Catch Specific Exceptions
impact: HIGH
impactDescription: Catching specific exceptions prevents swallowing unexpected errors
tags: error-handling, try-catch, exceptions, php8
---

# Catch Specific Exceptions

Always catch the most specific exception type possible. Never catch generic `\Exception` or `\Throwable` unless at the top-level error boundary.

## Bad Example

```php
<?php

declare(strict_types=1);

// Catches everything - hides bugs
try {
    $user = $repository->find($id);
    $mailer->sendWelcome($user);
    $logger->info('User welcomed');
} catch (\Exception $e) {
    // Was it a DB error? Mail error? A typo causing TypeError?
    // All swallowed silently
    return null;
}

// Even worse - catching Throwable swallows fatal errors
try {
    $result = $service->process($data);
} catch (\Throwable $e) {
    // This catches Error (type errors, OOM) - dangerous
    return 'Something went wrong';
}
```

## Good Example

```php
<?php

declare(strict_types=1);

// Catch specific exceptions with appropriate handling
try {
    $user = $repository->find($id);
    $mailer->sendWelcome($user);
} catch (UserNotFoundException $e) {
    $logger->warning('User not found', ['id' => $id]);
    return null;
} catch (MailerException $e) {
    // Email failure shouldn't block the flow - log and continue
    $logger->error('Welcome email failed', [
        'user_id' => $id,
        'error' => $e->getMessage(),
    ]);
}

// Multi-catch for same handling (PHP 8.0+)
try {
    $data = $api->fetch($endpoint);
} catch (ConnectionException | TimeoutException $e) {
    $logger->error('API unreachable', ['error' => $e->getMessage()]);
    throw new ServiceUnavailableException('External service down', previous: $e);
}

// Top-level boundary is the only place for broad catches
// e.g., in error handler, middleware, or command bus
try {
    $response = $kernel->handle($request);
} catch (\Throwable $e) {
    $logger->critical('Unhandled exception', [
        'exception' => $e::class,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    $response = new Response('Internal Server Error', 500);
}
```

## Why

- **No Hidden Bugs**: Unexpected exceptions bubble up instead of being silently swallowed
- **Appropriate Responses**: Different errors get different handling (404 vs 500 vs retry)
- **Better Debugging**: When something breaks, you see the actual error
- **Multi-Catch**: PHP 8.0+ `catch (A | B $e)` groups exceptions with same handling
- **Preserve Context**: Use `previous: $e` when re-throwing to keep the full chain
