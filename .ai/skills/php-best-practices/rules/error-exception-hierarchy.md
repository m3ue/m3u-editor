---
title: Exception Hierarchy
impact: HIGH
impactDescription: Proper inheritance enables layered catch blocks and consistent error handling
tags: error-handling, exceptions, hierarchy, php8
---

# Exception Hierarchy

Organize exceptions into a meaningful hierarchy so callers can catch at different levels of specificity.

## Bad Example

```php
<?php

declare(strict_types=1);

// Flat, unrelated exceptions - no hierarchy
class UserNotFoundException extends \Exception {}
class OrderNotFoundException extends \Exception {}
class ProductNotFoundException extends \Exception {}
class InvalidEmailException extends \Exception {}
class InvalidPriceException extends \Exception {}
class DatabaseConnectionException extends \Exception {}
class ApiTimeoutException extends \Exception {}

// Caller must catch each one individually
try {
    $order = $service->processOrder($data);
} catch (UserNotFoundException $e) {
    // handle
} catch (OrderNotFoundException $e) {
    // handle (same logic as above)
} catch (ProductNotFoundException $e) {
    // handle (same logic again)
}
```

## Good Example

```php
<?php

declare(strict_types=1);

// Base exception for the application
class AppException extends \RuntimeException {}

// Not-found family
class NotFoundException extends AppException
{
    public function __construct(
        private readonly string $entity,
        private readonly string|int $identifier,
    ) {
        parent::__construct("{$entity} not found: {$identifier}");
    }

    public function getEntity(): string
    {
        return $this->entity;
    }
}

class UserNotFoundException extends NotFoundException
{
    public function __construct(string|int $id)
    {
        parent::__construct('User', $id);
    }
}

class OrderNotFoundException extends NotFoundException
{
    public function __construct(string|int $id)
    {
        parent::__construct('Order', $id);
    }
}

// Validation family
class ValidationException extends AppException
{
    /** @param array<string, string> $errors */
    public function __construct(
        private readonly array $errors = [],
    ) {
        parent::__construct('Validation failed');
    }

    /** @return array<string, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}

// Infrastructure family
class InfrastructureException extends AppException {}
class DatabaseException extends InfrastructureException {}
class ExternalApiException extends InfrastructureException {}

// Callers can catch at any level
try {
    $order = $service->processOrder($data);
} catch (NotFoundException $e) {
    // Catches UserNotFound, OrderNotFound, ProductNotFound
    return response()->json(['error' => $e->getMessage()], 404);
} catch (ValidationException $e) {
    return response()->json(['errors' => $e->getErrors()], 422);
} catch (InfrastructureException $e) {
    // Catches Database, ExternalApi - log and show generic error
    $logger->error($e->getMessage());
    return response()->json(['error' => 'Service unavailable'], 503);
}
```

## Why

- **Layered Catching**: Catch broad categories or specific exceptions as needed
- **DRY Error Handling**: One catch block for all "not found" cases
- **Consistent Structure**: Shared base provides common interface
- **Extensible**: Add new exceptions without changing existing catch blocks
- **Use RuntimeException**: Extend `\RuntimeException` for errors that can't be recovered from programmatically
